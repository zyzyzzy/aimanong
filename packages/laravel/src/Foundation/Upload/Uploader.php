<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Upload;

use Aimanong\Aimanong;
use Aimanong\Contracts\UploadSpec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 上传处理器 —— 文件落盘与 URL 解析的**唯一**入口。
 *
 * ## 为什么要有它
 *
 * 竞品普遍把 `Storage::putFile()` 散落在各个控制器里，结果是：
 * 有的地方校验了扩展名、有的没校验；有的用随机名、有的用原始名
 * （原始名 = 直接拿用户的文件名当路径，经典的路径穿越/覆盖攻击面）。
 * 这里统一。
 *
 * ## 命名策略
 *
 * **绝不复用客户端文件名。** 一律 `日期目录/随机名.安全扩展名`：
 *   - 客户端名可能含 `../`、`:`、控制字符
 *   - 同名文件会互相覆盖
 *   - 中文/表情文件名在部分文件系统上会出问题
 *
 * 原始文件名只保存在数据库里（给用户看），不参与路径。
 */
class Uploader implements UploadSpec
{
    /*
     * 默认白名单与上限。
     *
     * 放在类常量而不是只写在 config 里：Uploader 会被 Schema 编译层
     * 在无容器环境（单测 / 静态自省）调用，那时读不到 config，
     * 若默认值只存在于配置里就会「白名单为空 → 全部上传被拒」。
     * config 文件直接引用这些常量，仍然只有一份来源。
     */
    public const DEFAULT_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    public const DEFAULT_FILE_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'zip', 'rar', '7z', 'mp4', 'mp3',
    ];

    public const DEFAULT_MAX_SIZE = 10240;

    public const DEFAULT_IMAGE_MAX_SIZE = 5120;

    /**
     * 落盘并返回文件信息。
     *
     * @return array{path: string, url: string, name: string, size: int, mime: string, disk: string}
     */
    public function store(UploadedFile $file, string $kind = 'file', ?string $directory = null): array
    {
        $disk = $this->disk();
        $directory = $this->normalizeDirectory($directory ?? $this->directory());

        $extension = $this->safeExtension($file);
        $basename = $this->storageBasename($file, $extension);

        // 按日期分子目录：单目录文件过多时，ls/同步/备份都会变慢
        $sub = date('Y/m');
        $path = trim($directory.'/'.$sub.'/'.$basename, '/');

        $stream = fopen($file->getRealPath(), 'r');

        if ($stream === false) {
            throw new RuntimeException('无法读取上传的临时文件。');
        }

        try {
            $ok = Storage::disk($disk)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($ok === false) {
            throw new RuntimeException("文件写入失败（disk: {$disk}）。请检查 storage 目录权限。");
        }

        return [
            'path' => $path,
            'url' => $this->url($path),
            'name' => $this->originalName($file),
            'size' => (int) $file->getSize(),
            'mime' => (string) $file->getClientMimeType(),
            'disk' => $disk,
            'kind' => $kind,
        ];
    }

    /**
     * 删除一个已上传文件。
     *
     * 只接受本框架生成的相对路径；外部 URL 一律忽略 ——
     * 否则一个 `->delete($userInput)` 就能删掉任意文件。
     */
    public function delete(mixed $value): bool
    {
        $path = $this->pathFromValue($value);

        if ($path === null) {
            return false;
        }

        try {
            return Storage::disk($this->disk())->delete($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 相对路径 → 可访问 URL。
     */
    public function url(string $path): string
    {
        if ($this->serve() === 'route') {
            try {
                return url(Aimanong::url('file/'.$path));
            } catch (Throwable) {
                // 无容器（单测 / 静态自省）：退回相对路径
                return $path;
            }
        }

        try {
            return Storage::disk($this->disk())->url($path);
        } catch (Throwable) {
            try {
                // 云盘没配 url 时退回框架路由，至少不是坏图
                return url(Aimanong::url('file/'.$path));
            } catch (Throwable) {
                return $path;
            }
        }
    }

    /**
     * 前端需要的上传配置（随 Schema 或页面下发给前端）。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'urlTemplate' => $this->urlTemplate(),
            'endpoint' => $this->endpoint(),
            'disk' => $this->disk(),
            'directory' => $this->directory(),
            'imageMaxSize' => $this->maxSize('image'),
            'fileMaxSize' => $this->maxSize('file'),
            'imageExtensions' => $this->allowedExtensions('image'),
            'fileExtensions' => $this->allowedExtensions('file'),
        ];
    }

    /**
     * URL 模板（含字面量 `{path}` 占位符）。
     *
     * 前端拿它把「存进数据库的相对路径」还原成可访问 URL，
     * 不需要再发一次请求，也不需要知道当前是 route 还是 url 模式。
     */
    public function urlTemplate(): string
    {
        try {
            if ($this->serve() === 'route') {
                return url(Aimanong::url('file')).'/{path}';
            }

            return Storage::disk($this->disk())->url('{path}');
        } catch (Throwable) {
            /*
             * 无容器场景（单元测试 / 不启动 Laravel 的静态自省）：
             * Aimanong::url() 解析不了上下文。给一个占位模板即可 ——
             * 真实请求里不可能走到这里。
             */
            return '/{path}';
        }
    }

    /**
     * 上传接口地址（随 Schema 下发给前端）。
     *
     * 与 urlTemplate 同理：无容器时退化为相对路径，真实请求里不会走到。
     */
    public function endpoint(): string
    {
        try {
            return url(Aimanong::url('api/upload'));
        } catch (Throwable) {
            return '/api/upload';
        }
    }

    /**
     * 把存进数据库的值统一成相对路径。
     *
     * 兼容三种历史形态：相对路径、完整 URL、`/storage/...` 形式。
     * 拿不准就返回 null（视为「不是本框架管理的文件」）。
     */
    public function pathFromValue(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $path = $value;

        // 完整 URL 或 /storage 前缀 → 取路径部分
        if (str_contains($path, '://')) {
            $parsed = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }

        $path = ltrim($path, '/');

        // 去掉 storage/ 与后台前缀
        $path = preg_replace('#^storage/#', '', $path) ?? $path;
        /*
         * 只剥 `file` 前缀。
         *
         * 曾同时剥 `uploads` —— 但存储目录本身就叫 uploads，
         * 于是 `/storage/uploads/2026/10/a.png` 会被剥成 `2026/10/a.png`，
         * 文件再也找不到。路由前缀与目录名重名就是这个下场。
         */
        foreach (['file'] as $base) {
            try {
                $prefix = trim(Aimanong::url($base), '/');
            } catch (Throwable) {
                // 无容器（单测 / 静态自省）：没有路由前缀可剥，继续即可
                $prefix = $base;
            }

            if ($prefix !== '' && str_starts_with($path, $prefix.'/')) {
                $path = substr($path, strlen($prefix) + 1);
                break;
            }
        }

        // 路径穿越一律拒绝
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        return $path;
    }

    /**
     * 值 → 前端可用的 URL（拿不准就原样返回，兼容用户自己存的外链）。
     */
    public function urlFromValue(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        /*
         * 已经是完整 URL 就原样返回。
         *
         * 这些是「不属于本框架管理」的地址：用户手填的 CDN 外链、
         * 老数据里存过的绝对地址。硬套框架模板会拼出
         * /admin/file/https://cdn... 这种必然 404 的地址。
         */
        if (preg_match('#^(https?:)?//#i', $value) === 1 || str_starts_with($value, 'data:')) {
            return $value;
        }

        $path = $this->pathFromValue($value);

        if ($path === null) {
            return $value;
        }

        return $this->url($path);
    }

    /**
     * 读配置，**无容器时退化为默认值**。
     *
     * Uploader 会被 Schema 编译层调用，而编译产物在单元测试、
     * 纯静态自省（不启动 Laravel）下也要能生成 ——
     * 直接调 config() 会抛 `ReflectionException: Class "config" does not exist`。
     */
    protected function cfg(string $key, mixed $default = null): mixed
    {
        try {
            return config($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    public function disk(): string
    {
        $disk = $this->cfg('aimanong.foundation.upload.disk', 'public');

        return is_string($disk) && $disk !== '' ? $disk : 'public';
    }

    public function directory(): string
    {
        $dir = $this->cfg('aimanong.foundation.upload.directory', 'uploads');

        return is_string($dir) ? trim($dir, '/') : 'uploads';
    }

    /**
     * 单文件体积上限（KB）。
     */
    public function maxSize(string $kind = 'file'): int
    {
        $key = $kind === 'image' ? 'image_max_size' : 'max_size';
        $value = $this->cfg(
            'aimanong.foundation.upload.'.$key,
            $kind === 'image' ? self::DEFAULT_IMAGE_MAX_SIZE : self::DEFAULT_MAX_SIZE
        );

        return is_numeric($value) ? (int) $value : 10240;
    }

    /**
     * 允许的扩展名白名单。
     *
     * @return array<int, string>
     */
    public function allowedExtensions(string $kind = 'file'): array
    {
        $key = $kind === 'image' ? 'image_extensions' : 'file_extensions';
        $value = $this->cfg(
            'aimanong.foundation.upload.'.$key,
            $kind === 'image' ? self::DEFAULT_IMAGE_EXTENSIONS : self::DEFAULT_FILE_EXTENSIONS
        );

        if (! is_array($value)) {
            return [];
        }

        /*
         * 🔴 危险扩展名**一律剔除**，不管 config 里写没写。
         *
         * `mimes:...,php,...` 会同时让 Laravel 的 shouldBlockPhpUpload() 失效 ——
         * 与其指望每个项目的配置都写对，不如在这里硬挡一道。
         * 剔除后若是空列表，调用方的「白名单为空」检查会给出明确报错。
         */
        $dangerous = self::forbiddenExtensions();

        $list = array_values(array_filter(array_map(
            fn (mixed $v): string => is_string($v) ? strtolower(trim($v, '.')) : '',
            $value
        )));

        // $list 上面已经滤掉空串，这里只滤危险扩展名
        return array_values(array_filter(
            $list,
            fn (string $ext): bool => ! in_array($ext, $dangerous, true)
        ));
    }

    /**
     * 用 `->accept()` 覆盖时的扩展名解析。
     *
     * @return array<int, string>
     */
    public function extensionsFor(string $kind, ?string $accept = null): array
    {
        if ($accept === null || trim($accept) === '') {
            return $this->allowedExtensions($kind);
        }

        $out = [];

        foreach (explode(',', $accept) as $token) {
            $token = strtolower(trim($token));

            if ($token === '') {
                continue;
            }

            // 支持 .png / png / image/* 三种写法
            if (str_contains($token, '/')) {
                $token = str_replace('image/', '', $token);
            }

            $token = ltrim($token, '.');

            /*
             * 只接受形如 png / docx 的 token。
             *
             * 之前是把 `???` 也原样收下 —— 结果是白名单里全是无效扩展名，
             * Laravel 的 mimes 规则永远匹配不上，**所有上传都被 422**，
             * 而用户完全不知道是自己 accept() 写错了。
             * 宁可用回默认白名单。
             */
            if (preg_match('/^[a-z0-9]{1,12}$/', $token) !== 1) {
                continue;
            }

            $out[] = $token;
        }

        /*
         * 🔴 关键：客户端给的 accept **只能收窄**，绝不能扩宽。
         *
         * ## 原先的写法是 RCE 级漏洞（2026-10-16 审计实测复现）
         *
         * `accept` 来自请求参数，而这个方法把它**原样**当成白名单用：
         *
         *     POST /admin/api/upload
         *       -F "file=@x.php;type=image/gif"   # 内容以 GIF89a 开头骗过 finfo
         *       -F "kind=file" -F "accept=php,gif"
         *     → 201 {"path":"uploads/.../xxx.php"}   # 落盘的是可执行 PHP
         *
         * 而且 `accept=php` 还有连带伤害：Laravel 的 shouldBlockPhpUpload()
         * 一旦发现规则里出现 `php` 就直接 return false ——
         * **它是来挡 PHP 的，结果被请求参数缴了械**。
         *
         * 所以：候选扩展名必须**同时**出现在服务端的 kind 白名单里。
         */
        $server = $this->allowedExtensions($kind);
        $narrowed = [];

        foreach ($out as $token) {
            if (in_array($token, $server, true)) {
                $narrowed[] = $token;
            }
        }

        // accept 里全是服务端不认的类型时，退回默认白名单（不能放开成任意文件）
        return $narrowed === [] ? $server : array_values(array_unique($narrowed));
    }

    /**
     * 永远不允许的扩展名 —— 即使有人在 config 里写了也拒绝。
     *
     * 这些扩展名一旦落到能被 Web 服务器解析的目录就是 RCE。
     * 框架自己推荐过 `storage:link` / `serve=url`（都是文档化选项），
     * 所以「storage 不会被解析」这个前提**不成立**。
     *
     * @return array<int, string>
     */
    public static function forbiddenExtensions(): array
    {
        return [
            // PHP 家族
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht', 'phar',
            // 其它常见可执行 / 可被服务器解析
            'cgi', 'pl', 'py', 'rb', 'jsp', 'jspx', 'asp', 'aspx', 'ashx', 'shtml',
            'htaccess', 'htpasswd',
        ];
    }

    /**
     * 解析 serve 模式。
     *
     * ## 为什么 auto 按 driver 判断，而不是「有没有配 url」
     *
     * Laravel 骨架的默认 `public` 盘**总是**配了 `url`（取自 APP_URL），
     * 所以「有 url 就用 Storage::url()」会让 auto 永远走 url 模式 ——
     * 而 url 模式依赖 `php artisan storage:link` 与正确的 APP_URL，
     * 新项目大概率是坏图（实测：URL 直接指向 http://localhost/storage/...）。
     *
     * 因此按 driver 判断：
     *   - local 驱动 → 框架读取路由（免 storage:link，立刻可用，
     *     且天然要求登录 —— 后台上传的合同附件本来就不该匿名可下）
     *   - 其它驱动（s3/oss/cos 等）→ Storage::url()，走 CDN
     *
     * 想让本地文件也能匿名直读（例如同一批图给官网用）：
     * 把 serve 设成 url，并执行 php artisan storage:link。
     */
    public function serve(): string
    {
        $mode = $this->cfg('aimanong.foundation.upload.serve', 'auto');

        if ($mode === 'route' || $mode === 'url') {
            return $mode;
        }

        $driver = $this->cfg('filesystems.disks.'.$this->disk().'.driver');

        return $driver === 'local' || $driver === null ? 'route' : 'url';
    }

    /**
     * 生成落盘文件名。
     *
     * ## 为什么要保留原始名
     *
     * 纯随机名（`T7FJgPtROo9L4NXF1Fkf2y5x.pdf`）在列表里毫无意义 ——
     * 用户传了《2026年采购合同.pdf》，一个月后在后台只看到一串随机字符，
     * 根本认不出是哪个文件。实测踩过。
     *
     * ## 为什么还要加随机后缀
     *
     * 只用原始名会撞名：两个人都传 `合同.pdf` 时后者会**静默覆盖**前者。
     * 所以取「可读原名-8位随机」的形式，兼顾可读与不撞。
     *
     * ## 为什么过滤得过狠
     *
     * 文件名来自客户端，可能含 `../`、`:`、控制字符、Windows 保留名。
     * 这里只放行汉字/字母/数字与 `. _ -`，其余一律换成 `-`。
     * 保留汉字是因为本框架面向中文项目，且已验证中文路径
     * 在存储与 URL 解析全链路可用。
     */
    protected function storageBasename(UploadedFile $file, string $extension): string
    {
        $original = $this->originalName($file);

        // 去掉扩展名（稍后统一拼回安全扩展名）
        $stem = pathinfo($original, PATHINFO_FILENAME);

        $stem = preg_replace('/[^\p{Han}A-Za-z0-9._-]+/u', '-', $stem) ?? '';
        $stem = trim($stem, '-._');

        if ($stem === '') {
            $stem = 'file';
        }

        // 字节截断，避免超长文件名在不同文件系统上被拒绝
        if (strlen($stem) > 60) {
            $stem = mb_strcut($stem, 0, 60, 'UTF-8');
        }

        $suffix = strtolower(Str::random(8));

        return $stem.'-'.$suffix.($extension === '' ? '' : '.'.$extension);
    }

    /**
     * 取安全的扩展名 —— 以**文件内容判定**为准，客户端文件名只作兜底。
     *
     * ## 为什么不能信 getClientOriginalExtension()
     *
     * 那个值完全来自客户端。审计实测：
     *
     *     -F "file=@x.php;type=image/gif"   # 内容 "GIF89a<?php system($_GET['c']); ?>"
     *     → 落盘 uploads/.../xxx.php        # 扩展名照抄客户端文件名
     *
     * 即使 MIME 校验放行（内容确实是 image/gif），落盘也不该叫 `.php`。
     * 用 `guessExtension()`（Laravel 按真实内容嗅探）就得到 `gif` ——
     * 文件依旧是那张图，但**不会被任何 Web 服务器当脚本执行**。
     *
     * ⚠️ 客户端 MIME + 扩展名双白名单已在控制器层校验过；这里只负责
     * 让落盘名不产生歧义。内容判定拿不到时才退回客户端扩展名（仍会被
     * 危险扩展名黑名单挡一道）。
     */
    protected function safeExtension(UploadedFile $file): string
    {
        $fromContent = $this->normalizeExtension((string) $file->guessExtension());
        $fromName = $this->normalizeExtension((string) $file->getClientOriginalExtension());

        $dangerous = self::forbiddenExtensions();
        $candidates = [$fromContent, $fromName];

        foreach ($candidates as $ext) {
            if ($ext !== '' && ! in_array($ext, $dangerous, true)) {
                return $ext;
            }
        }

        // 两个来源都拿不到安全扩展名 —— 用 bin，绝不回落到客户端给的
        return 'bin';
    }

    /**
     * 只留字母数字，挡掉 "php\x00.jpg" 这类花活。
     */
    protected function normalizeExtension(string $ext): string
    {
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext) ?? '');

        return substr($ext, 0, 12);
    }

    protected function originalName(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();

        return mb_substr(basename(str_replace('\\', '/', $name)), 0, 190);
    }

    protected function normalizeDirectory(string $directory): string
    {
        $directory = str_replace('\\', '/', $directory);
        $parts = array_filter(explode('/', $directory), fn (string $p): bool => $p !== '' && $p !== '.' && $p !== '..');

        return implode('/', $parts);
    }
}
