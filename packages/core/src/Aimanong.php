<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Contracts\ApplicationContext;
use Aimanong\Contracts\FrameworkRuntime;
use Aimanong\Exceptions\DriverNotRegisteredException;
use Aimanong\Support\Asset;
use Aimanong\Support\Drivers;
use Composer\InstalledVersions;

/**
 * 框架主入口 —— 用户代码与模板都从这里取东西。
 *
 *     Aimanong::registry()->register(\App\Aimanong\UserResource::class);
 *     Aimanong::url('orders')
 *     Aimanong::version()
 *
 * ## 为什么它住在 core
 *
 * 用户代码（文档也教）写 `Aimanong::registry()`，框架自己的 10 个 Blade
 * 模板写 `Aimanong::url()/brand()/asset()/version()` —— 所以这个类名
 * **必须在两个框架下都存在**。它只能有一个定义，那就是 core。
 *
 * 框架专属的部分（guard / 多应用 / 扩展）通过 `Contracts\FrameworkRuntime`
 * 接出去，由各驱动包注册 —— 见那个契约的注释。
 */
final class Aimanong
{
    protected static bool $booted = false;

    protected static ?FrameworkRuntime $runtime = null;

    /**
     * 注册框架运行时（由驱动包在启动时调用）。
     */
    public static function bindRuntime(FrameworkRuntime $runtime): void
    {
        self::$runtime = $runtime;
    }

    /**
     * 取运行时；未注册时抛明确错误。
     *
     * 不返回 null、不静默降级 —— 入口是用户代码的第一站，
     * 这里拿到 null 会在很远的地方以莫名其妙的方式炸掉。
     */
    public static function runtime(): FrameworkRuntime
    {
        return self::$runtime ?? throw new DriverNotRegisteredException(
            'FrameworkRuntime',
            '框架入口（Aimanong::url()/registry() 等）',
            Drivers::registeredNames()
        );
    }

    // ─────────────────────────────────────────────
    // 框架无关的部分
    // ─────────────────────────────────────────────

    /**
     * 后台 URL（含应用前缀）。
     */
    public static function url(string $path = ''): string
    {
        /*
         * ⚠️ 前缀在这里**强制归一化成带前导斜杠**。
         *
         * 不能假设驱动返回的已经是 `/admin`：Laravel 侧曾经返回 `admin`
         * （配置里的原样值），于是这里拼出 `admin/assets/x.css` ——
         * 一个**相对路径**。浏览器按当前页面的 baseURI 解析它：
         *
         *     页 /admin           → /admin/assets/...          ✅（base 是 /，碰巧对）
         *     页 /admin/articles  → /admin/admin/assets/...    ❌ 全部 404
         *
         * 也就是「控制台页正常、所有资源页面全坏」。它躲过了 223 个单测 +
         * 147 条 e2e 断言 + 一次全面验收，因为 e2e 只测了深度 1 的控制台页；
         * 最后是 5 个独立 AI agent 在真实浏览器里各自撞到才暴露。
         *
         * 归一化放在**这里**而不是各驱动里：这是唯一构建 URL 的地方，
         * 放这里等于从结构上消灭了这一类 bug（新驱动不可能再犯）。
         */
        $prefix = '/'.trim(self::runtime()->urlPrefix(), '/');
        $prefix = rtrim($prefix, '/');

        /*
         * ⚠️ 空路径返回 `/admin`（**不带尾斜杠**）。
         *
         * 原实现返回 `/admin/`。Laravel 两种都能路由，但 ThinkPHP 的显式规则
         * 不做尾斜杠归一化 —— 侧边栏品牌行的 link（`Aimanong::url()` 空参）
         * 恰好就是 `/admin/`，于是**点 LOGO 回首页得到 404**。
         *
         * 统一成不带尾斜杠的形态：`/admin` 在两端都稳定命中。
         */
        if ($path === '') {
            return $prefix;
        }

        return $prefix.'/'.ltrim($path, '/');
    }

    /**
     * Resource 注册表。
     */
    public static function registry(): Registry
    {
        return self::runtime()->registry();
    }

    /**
     * 前端静态资源。
     */
    public static function asset(): Asset
    {
        return self::runtime()->asset();
    }

    /**
     * 品牌名（控制台标题与页脚用）。
     *
     * 可在 config/aimanong.php 里改 ui.brand 换成自己的系统名 ——
     * 基于 Aimanong 开发的项目通常不希望页脚写着框架名。
     */
    public static function brand(): string
    {
        try {
            $brand = self::runtime()->config('aimanong.ui.brand');
        } catch (\Throwable) {
            $brand = null;
        }

        return is_string($brand) && $brand !== '' ? $brand : 'Aimanong';
    }

    /**
     * 框架引导（幂等）。
     */
    public static function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
    }

    /**
     * 版本号 —— **单一来源**：`aimanong/framework` 的 composer.json。
     *
     * ## 拆包之后，「版本号」指的是什么
     *
     * 一次安装会引入三个包，各有自己的版本：
     *
     *     aimanong/framework  1.9.1   ← 用户装的、对外的**产品版本**
     *     aimanong/laravel    1.0.1   ← 内部驱动包
     *     aimanong/core       1.x     ← 内部内核包
     *
     * 后台界面顶上显示的、文档里写的、README 徽章上的，都应该是
     * **产品版本**（framework），不是内部包的版本 —— 用户装的是 framework，
     * 他关心的是那个号码。内部包怎么升版不该影响他看到的数字。
     *
     * ## 三个来源，顺序不能乱（每一步都踩过坑）
     *
     *   ① Composer 的 `InstalledVersions`（装在应用里时）
     *   ② `framework/composer.json` 的相对路径（仓库内开发时）
     *   ③ 本包自己的 composer.json（兜底）
     *
     * ⚠️ ① 必须在 ③ 之前。`aimanong/framework` 是 metapackage，
     * Composer **不为它建目录**，所以 ② 会落空；若此时直接落 ③，
     * 就会返回驱动包的版本 —— 真实安装里实测到过这个错误
     * （界面显示 1.0.0 而非 1.9.0），而且只有真装一次才看得见。
     */
    public static function version(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        if (class_exists(InstalledVersions::class)
            && InstalledVersions::isInstalled('aimanong/framework')) {
            $ver = InstalledVersions::getPrettyVersion('aimanong/framework');

            if (is_string($ver) && $ver !== '') {
                return $cached = $ver;
            }
        }

        /*
         * 仓库内开发：向上找 `packages/framework/composer.json`。
         *
         * ⚠️ 不能只写死 `__DIR__.'/../../framework'`。
         *
         * 那个相对路径**只在 core 被软链到 monorepo 时才成立**：
         *
         *   开发机：vendor/aimanong/core → 软链 → monorepo/packages/core
         *          → `../../framework` = monorepo/packages/framework ✅ 1.11.0
         *
         *   CI/用户：vendor/aimanong/core 是**真实目录**（从 Packagist 装的）
         *          → `../../framework` 不存在
         *          → 落到下一档 core/composer.json → **返回 core 的版本 1.6.3** ❌
         *
         * 而 core 与 framework 是**两个不同版本号的包**，
         * 于是「框架版本」静默变成了 core 的版本 ——
         * 一个只在 CI 里暴露、本地永远复现不了的错。
         *
         * 现在从本文件所在目录逐级向上找 `packages/framework/composer.json`：
         *   · monorepo（无论 core 是软链还是拷贝）→ 找得到 ✅
         *   · 用户安装（vendor/aimanong/core/src）→ 向上找不到 → 走兜底
         */
        if (($ver = self::findFrameworkVersion(__DIR__)) !== null) {
            return $cached = $ver;
        }

        if (($ver = self::versionFromComposer(__DIR__.'/../composer.json')) !== null) {
            return $cached = $ver;
        }

        return $cached = '1.0.0';
    }

    /**
     * 从给定目录向上查找 monorepo 里的 `packages/framework/composer.json`。
     *
     * 最多向上 8 级（够覆盖 `vendor/aimanong/core/src` → 仓库根）。
     */
    protected static function findFrameworkVersion(string $start): ?string
    {
        /*
         * ⚠️ 先归一化再向上走。
         *
         * `dirname()` 把 `..` 当**普通路径段**，不做解析：
         *     dirname("/a/b/Unit/../../../core") = "/a/b/Unit/../../.."
         *     dirname(那个)                      = "/a/b/Unit/../.."
         * 于是「向上 N 级」在字符串空间里数错位，永远走不到仓库根。
         *
         * 真实调用点传 `__DIR__`（绝对且无 `..`），线上没事 ——
         * 但一个「向上查找」的辅助函数不该只在特定输入下正确。
         */
        $dir = realpath($start) ?: self::normalizePath($start);

        for ($i = 0; $i < 8; $i++) {
            $candidate = $dir.'/packages/framework/composer.json';

            if (($ver = self::versionFromComposer($candidate)) !== null) {
                return $ver;
            }

            $parent = \dirname($dir);

            if ($parent === $dir) {
                break; // 到根了
            }

            $dir = $parent;
        }

        return null;
    }

    /**
     * 归一化路径（解析 `.` 与 `..`），不要求路径真实存在。
     */
    protected static function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);
                } elseif (! $isAbsolute) {
                    $parts[] = '..';
                }

                continue;
            }

            $parts[] = $segment;
        }

        return ($isAbsolute ? '/' : '').implode('/', $parts);
    }

    // ─────────────────────────────────────────────
    // 框架专属的部分（类型由驱动决定，见 FrameworkRuntime）
    // ─────────────────────────────────────────────

    /**
     * 当前后台的认证 guard。
     *
     * 返回 `object`：真实类型（Laravel 的 AdminGuard）住在驱动包里，
     * core 写死它就又绑回去了。调用点需要精确类型时用局部 `@var`。
     */
    public static function guard(): object
    {
        return self::runtime()->guard();
    }

    /**
     * 多应用上下文。
     */
    public static function context(): ApplicationContext
    {
        /** @var ApplicationContext $ctx */
        $ctx = self::runtime()->context();

        return $ctx;
    }

    /**
     * 多应用管理器。
     */
    public static function application(): object
    {
        return self::runtime()->application();
    }

    /**
     * 扩展管理器。
     */
    public static function extensions(): object
    {
        return self::runtime()->extensions();
    }

    /**
     * 当前登录用户；未登录返回 null。
     */
    public static function user(): ?object
    {
        return self::runtime()->user();
    }

    /**
     * 从 composer.json 读 version 字段；读不到返回 null。
     */
    protected static function versionFromComposer(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable) {
            return null;
        }

        if (is_array($decoded)
            && isset($decoded['version'])
            && is_string($decoded['version'])
            && $decoded['version'] !== '') {
            return $decoded['version'];
        }

        return null;
    }
}
