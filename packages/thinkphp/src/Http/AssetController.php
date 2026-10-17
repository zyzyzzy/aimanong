<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http;

use Aimanong\Support\Asset;
use think\exception\HttpException;
use think\Request;
use think\response\File;

/**
 * 框架内置静态资源（设计系统 CSS / Vue 运行时 / LOGO）。
 *
 * ## 为什么需要它
 *
 * 布局模板里有 3 个外链 CSS 与若干 JS：
 *
 *     <link rel="stylesheet" href=".../assets/css/design-system.css">
 *
 * 没有这条路由时它们全部 404 —— **后台完全没有样式**，
 * 而页面本身返回 200，不会报任何错（只有打开开发者工具才看得到）。
 *
 * ## 资源文件在 core
 *
 * `Aimanong\Support\Asset::path()` 定位到 `aimanong/core/resources/assets`。
 * 放在 core 而不是各驱动包里，是因为这些是**框架无关的前端资源**，
 * 两个驱动共用一份 —— 复制两份会变成要同步的双真源。
 *
 * ## 与 Laravel 侧一致性
 *
 * MIME 表、目录穿越防护与 Laravel 侧逐条对应。
 */
final class AssetController
{
    /**
     * 文件名 → MIME。
     *
     * ⚠️ **必须显式指定**。让框架/浏览器去猜会出现
     * 「CSS 返回 text/html → 浏览器拒绝解析 → 样式完全不生效」，
     * 而请求是 200，排查起来毫无线索（Laravel 侧实测踩过）。
     */
    private const MIMES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
    ];

    public function show(Request $request, string $path): File
    {
        $base = realpath(Asset::path());
        $full = realpath(Asset::path($path));

        /*
         * 防目录穿越。
         *
         * `realpath` 会把 `../` 解析掉，再确认结果仍在资源目录内 ——
         * 光看原始字符串里有没有 `..` 是不够的（编码、软链接都能绕）。
         * 与 Laravel 侧同一套判断。
         */
        if ($full === false || $base === false || ! str_starts_with($full, $base) || ! is_file($full)) {
            throw new HttpException(404, '资源不存在');
        }

        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = self::MIMES[$ext] ?? 'application/octet-stream';

        /*
         * ⚠️ 必须用 `mimeType()` 和 `force(false)`，**不能**只 `header()`。
         *
         * `think\response\File::output()` 会**覆盖** Content-Type
         * （用 finfo 猜，`.css` 猜成 text/plain、`.css` 里的内容还可能猜成
         * text/html），并且默认 `force = true` 会加上
         * `Content-Disposition: attachment` —— 浏览器会把 CSS/JS **当附件下载**
         * 而不是加载，页面照样没样式。
         *
         * 这两个都是实测踩到的：只设 header 时 `.css` 返回 text/plain。
         */
        /*
         * 缓存策略（与 Laravel 侧的 assets 路由保持一致）。
         *
         * ## 为什么不能无条件 `expire(31536000)`
         *
         * 原先这里固定缓存一年 —— 那意味着**改了 CSS 也永远拿不到新的**，
         * 除非用户手动强刷（Ctrl+F5）。而 CSS 是随代码改的，
         * 框架版本号只在发版时变 —— 于是「改了 CSS 但还没发版」这段时间里，
         * 所有在用项目都停在旧样式上。
         *
         * 实测症状（批 2 加 list/cards 形态后，用户截图报的）：
         * 卡片彼此贴在一起、内部也没有间距 —— `.am-cards` 等规则在旧 CSS 里
         * 根本不存在，而服务端查什么都对、单测也全绿。
         *
         * ## 现在的规则
         *
         * 带指纹（`&h=`）→ 内容变了 URL 就变，缓存一年也无妨；
         * 不带指纹    → 不缓存，每次协商（内容没变会走 304）。
         */
        $response = (new File($full, 200))
            ->mimeType($mime)
            ->force(false);

        if ($request->param('h') !== null && $request->param('h') !== '') {
            return $response->expire(31536000);
        }

        return $response->header(['Cache-Control' => 'no-cache, must-revalidate']);
    }
}
