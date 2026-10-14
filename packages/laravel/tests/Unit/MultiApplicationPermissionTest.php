<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Auth\PermissionGate;
use Aimanong\Auth\PermissionGateChecker;
use Aimanong\Drivers\Eloquent\EloquentIntrospector;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Foundation\Upload\Uploader;
use Aimanong\Support\Drivers;
use Aimanong\Support\SingleApplication;
use Aimanong\Tests\Unit\Fixtures\FakeApplicationResolver;
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
    public function test_app_slug_forces_prefix_when_app_given(): void
    {
        $this->assertSame('admin.orders.index', PermissionGate::appSlug('admin', 'orders'));
        // slug() 不传应用 → 仍按当前上下文（单应用 = 不加前缀）
        $this->assertSame('orders.index', PermissionGate::slug('orders'));
    }
}
