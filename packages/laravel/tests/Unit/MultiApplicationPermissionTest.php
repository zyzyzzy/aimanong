<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Aimanong;
use Aimanong\Auth\PermissionGate;
use Aimanong\Auth\PermissionGateChecker;
use Aimanong\Drivers\Eloquent\EloquentIntrospector;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Foundation\Upload\Uploader;
use Aimanong\Support\Drivers;
use Aimanong\Support\SingleApplication;
use Aimanong\Tests\Unit\Fixtures\FakeApplicationResolver;
use Aimanong\Tests\Unit\Fixtures\FakeFrameworkRuntime;
use PHPUnit\Framework\TestCase;

/**
 * 多应用的权限节点命名。
 *
 * ## 这个测试最重要的断言是「单应用不变」
 *
 * 权限 slug 加应用前缀是这四段里**唯一有兼容性代价**的改动：
 * 已有项目里 `admin_permissions` 存着 `orders.index` 这样的节点，
 * 一旦改名，所有已配置的授权全部失效。
 *
 * 所以设计成「只在多应用开启时加前缀」——
 * `test_single_app_slug_unchanged` 就是钉死这条的。
 */
class MultiApplicationPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Drivers::reset();

        // Aimanong::url() 走公开入口，需要绑定运行时（单测起不了容器）
        Aimanong::bindRuntime(new FakeFrameworkRuntime);
    }

    protected function tearDown(): void
    {
        // 恢复 bootstrap 那一套（见 MultiApplicationMenuTest 的说明）
        Drivers::reset();
        Drivers::registerIntrospector(new EloquentIntrospector);
        Drivers::registerDictionary(new Dictionary);
        Drivers::registerPermissionChecker(new PermissionGateChecker);
        Drivers::registerUploadSpec(new Uploader);

        parent::tearDown();
    }

    /**
     * 单应用（默认）：slug 必须与改动前**逐字相同**。
     *
     * 这条是兼容性红线 —— 它一红，意味着已有项目的授权会全丢。
     */
    public function test_single_app_slug_unchanged(): void
    {
        $this->assertInstanceOf(SingleApplication::class, Drivers::application());

        $this->assertSame('orders.index', PermissionGate::slug('orders'));
        $this->assertSame('orders.create', PermissionGate::slug('orders', 'create'));
        $this->assertSame('orders.index', PermissionGate::slug('orders', ['index', 'show']));
    }

    /**
     * 多应用：slug 带上当前后台前缀。
     */
    public function test_multi_app_slug_prefixed(): void
    {
        Drivers::registerApplicationResolver((new FakeApplicationResolver)->withCurrent('agent'));

        $this->assertSame('agent.orders.index', PermissionGate::slug('orders'));
        $this->assertSame('agent.orders.update', PermissionGate::slug('orders', 'update'));
    }

    /**
     * 同一个 Resource，在不同后台是不同的权限节点。
     *
     * 这正是「给代理商开权限 ≠ 给总后台开权限」的落点。
     */
    public function test_same_resource_different_apps_are_different_nodes(): void
    {
        $admin = PermissionGate::appSlug('admin', 'orders', 'index');
        $agent = PermissionGate::appSlug('agent', 'orders', 'index');
        $user = PermissionGate::appSlug('user', 'orders', 'index');

        $this->assertNotSame($admin, $agent);
        $this->assertNotSame($admin, $user);
        $this->assertSame('admin.orders.index', $admin);
        $this->assertSame('agent.orders.index', $agent);
    }

    /**
     * 显式指定应用时，不受「当前后台」影响。
     *
     * 同步权限（`sync()`）在总后台跑，但要为所有后台建节点 ——
     * 那时必须能指定应用名。
     */
    public function test_app_slug_is_explicit(): void
    {
        Drivers::registerApplicationResolver((new FakeApplicationResolver)->withCurrent('admin'));

        // 当前是 admin，但显式要 user 的
        $this->assertSame('user.orders.index', PermissionGate::appSlug('user', 'orders'));
    }

    /**
     * 与 ThinkPHP 侧**对拍**：读同一份共享期望文件。
     *
     * 项目主张「同一套声明，多框架行为一致」——
     * 而 slug 的拼法是两端各自实现的（Laravel `PermissionGate::appSlug`、
     * TP `ThinkPermissionChecker::appSlug`），**会漂移**。
     *
     * 各自硬编码期望值 = 「各自能跑」；从同一份文件读，
     * 漂移的那一侧才会红。
     */
    public function test_slug_shape_matches_shared_expectation(): void
    {
        $exp = self::sharedExpectations();

        // 单应用：{uri}.{action}（与改动前逐字相同 —— 兼容性红线）
        $expectSingle = str_replace(
            ['{uri}', '{action}'],
            ['orders', 'index'],
            (string) $exp['slug']['single_app']
        );

        $this->assertInstanceOf(SingleApplication::class, Drivers::application());
        $this->assertSame($expectSingle, PermissionGate::slug('orders'));

        // 多应用：{app}.{uri}.{action}
        $expectMulti = str_replace(
            ['{app}', '{uri}', '{action}'],
            ['admin', 'orders', 'index'],
            (string) $exp['slug']['multi_app']
        );

        $this->assertSame($expectMulti, PermissionGate::appSlug('admin', 'orders', 'index'));
    }

    /**
     * 共享期望文件（与 TP 的 e2e 读同一份）。
     *
     * @return array<string, mixed>
     */
    protected static function sharedExpectations(): array
    {
        $path = dirname(__DIR__, 4).'/tests/e2e/fixtures/multi-app-expectations.json';
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            self::fail("读不到跨框架期望文件: {$path}");
        }

        return $data;
    }

    /**
     * 显式指定应用时**强制**加前缀，即使当前是单应用。
     *
     * 为什么这样设计（而不是「单应用就不加」）：
     * CLI（如 `aimanong:permission migrate-permissions`）**没有请求上下文**，
     * 拿不到「当前是哪个后台」，只能靠调用方显式指定。
     * 若显式指定时还受 enabled() 影响，迁移命令就永远建不出目标节点。
     *
     * 影响面：`slug()`（不传应用）仍然按 enabled() 决定 ——
     * 单应用项目走 `slug()` 拿到的还是不带前缀的，兼容性不受影响。
     */
    /**
     * URL 形状对拍（与 TP 的 e2e 读同一份期望文件）。
     *
     * 实测事故：`urlPrefix()` 没归一化时 `Aimanong::url()` 产出
     * `admin/assets/x.css`（**无前导斜杠**）。那是相对路径，
     * 在深度≥2 的页面（`/admin/articles`）里被浏览器解析成
     * `/admin/admin/assets/...` → 全部 404 ——
     * 而控制台页（深度 1）因 base 是 `/` 碰巧正常，
     * 所以 e2e 只测控制台时**永远是绿的**。
     *
     * 最后是 5 个独立 AI agent 在真实浏览器里各自撞到才暴露。
     */
    public function test_url_shape_matches_shared_expectation(): void
    {
        $exp = self::sharedExpectations();

        $this->assertTrue(
            (bool) ($exp['url']['must_start_with_slash'] ?? false),
            '共享期望文件里应声明 url.must_start_with_slash = true'
        );

        $sample = (string) ($exp['url']['sample']['path'] ?? 'assets/css/x.css');
        $expectPrefix = (string) ($exp['url']['sample']['expect_prefix'] ?? '/');

        if ($expectPrefix === '') {
            $expectPrefix = '/';
        }

        $url = Aimanong::url($sample);

        $this->assertStringStartsWith(
            $expectPrefix,
            $url,
            "Aimanong::url('{$sample}') 必须带前导斜杠（得到 '{$url}'）"
        );

        // 空参（侧边栏品牌行用的就是它）
        $home = Aimanong::url();
        $this->assertStringStartsWith('/', $home, "Aimanong::url() 必须带前导斜杠（得到 '{$home}'）");
        $this->assertStringEndsNotWith('/', $home, '空参不应带尾斜杠（TP 的显式路由不做归一化）');
    }

    public function test_app_slug_forces_prefix_when_app_given(): void
    {
        $this->assertSame('admin.orders.index', PermissionGate::appSlug('admin', 'orders'));
        // slug() 不传应用 → 仍按当前上下文（单应用 = 不加前缀）
        $this->assertSame('orders.index', PermissionGate::slug('orders'));
    }
}
