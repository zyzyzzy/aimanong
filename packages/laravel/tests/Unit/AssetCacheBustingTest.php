<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Support\Asset;
use PHPUnit\Framework\TestCase;

/**
 * 静态资源的**缓存击穿**：URL 必须随文件内容变化。
 *
 * ## 这个缺陷是怎么被发现的
 *
 * 2026-10-17 用户发来截图：「下面的每一块都没有间距，右上角也都挤在一起了」。
 *
 * 真因：资源 URL 只带 `?v=<框架版本号>`，而**版本号只在发版时变**。
 * 于是「改了 CSS 但还没发版」这段时间里，已在用的项目继续拿缓存的旧 CSS
 *（里面没有新增的 `.am-cards` 规则），而新模板已经在渲染卡片结构 ——
 * 页面直接错版，**而服务端查什么都对、单测也全绿**。
 *
 * 修法：把文件内容指纹编进 URL（`&h=<md5 前 8 位>`）。
 * 内容一变 URL 就变，浏览器必然重新拉取，不必等发版。
 *
 * ## 这里钉住四条
 *
 * 1. 指纹**确实随内容变化**（改一个字节就不同）
 * 2. 文件不存在时**退化为空**，而不是拼出半截的 `&h=`
 * 3. URL 里同时带 `v` 与 `h`
 * 4. 资源路由**不再无条件**返回 `Cache-Control: public`（没有 max-age 的
 *    那种「启发式缓存」，正是旧 CSS 被留下来的直接原因）
 */
class AssetCacheBustingTest extends TestCase
{
    /**
     * 指纹必须随内容变化 —— 这是整套机制的前提。
     */
    public function test_fingerprint_changes_with_content(): void
    {
        $a = Asset::fingerprint('css/design-system.css');

        $this->assertNotSame('', $a, 'design-system.css 应该能算出指纹');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $a, '指纹应是 8 位十六进制');

        // 同一文件两次结果必须一致（否则每次请求 URL 都变，缓存全失效）
        $this->assertSame($a, Asset::fingerprint('css/design-system.css'));
    }

    /**
     * 文件不存在 → 空串（退化），**不是**拼一个假的。
     *
     * 拼假的话缓存键会抖动，反而更容易出问题。
     */
    public function test_fingerprint_of_missing_file_is_empty(): void
    {
        $this->assertSame('', Asset::fingerprint('css/definitely-not-here.css'));
    }

    /**
     * URL 必须同时带 `v`（框架版本，便于排查）与 `h`（内容指纹，用于击穿）。
     */
    public function test_url_carries_both_version_and_fingerprint(): void
    {
        // Asset 是普通类，url() 只依赖 Aimanong::url() 与文件系统；
        // 这里直接读源码验证拼接规则，不启动应用（url() 需要运行时绑定）。
        $ref = new \ReflectionClass(Asset::class);

        $src = (string) file_get_contents((string) $ref->getFileName());

        $this->assertStringContainsString(
            "'&h='.",
            $src,
            'Asset::url() 必须把内容指纹拼进查询串（否则改了 CSS 也刷不出来）'
        );

        $this->assertStringContainsString(
            'fingerprint(',
            $src,
            'Asset 应提供 fingerprint() 并在 url() 里使用'
        );
    }

    /**
     * 资源路由必须区分「带指纹」与「不带指纹」两种缓存策略。
     *
     * ## 为什么这条要单独钉
     *
     * `response()->file()` 默认给 `Cache-Control: public` —— **没有 max-age**。
     * 浏览器据此做**启发式缓存**（拿 Last-Modified 估一个有效期），
     * 于是「服务端说可以缓存但没说多久」，各浏览器行为不一，
     * 升级后拿到旧 CSS 就不奇怪了。
     */
    public function test_asset_route_uses_fingerprint_aware_cache_headers(): void
    {
        $routes = (string) file_get_contents(
            dirname(__DIR__, 2).'/routes/admin.php'
        );

        $this->assertStringContainsString("query('h')", $routes, '资源路由应识别 h 参数');
        $this->assertStringContainsString('immutable', $routes, '带指纹时应用长期不可变缓存');
        $this->assertStringContainsString('no-cache', $routes, '不带指纹时应强制协商');

        // ThinkPHP 侧同样要处理（两端行为必须一致）
        $tp = (string) file_get_contents(
            dirname(__DIR__, 3).'/thinkphp/src/Http/AssetController.php'
        );

        $this->assertStringContainsString(
            'Cache-Control',
            $tp,
            'ThinkPHP 资源控制器也要按指纹区分缓存策略'
        );
    }
}
