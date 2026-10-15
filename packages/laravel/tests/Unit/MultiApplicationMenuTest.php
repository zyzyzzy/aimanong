<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Aimanong;
use Aimanong\Auth\PermissionGateChecker;
use Aimanong\Contracts\ApplicationResolver;
use Aimanong\Drivers\Eloquent\EloquentIntrospector;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Foundation\Resources\AdminUserResource;
use Aimanong\Foundation\Upload\Uploader;
use Aimanong\Menu\MenuRegistry;
use Aimanong\Support\Drivers;
use Aimanong\Support\SingleApplication;
use Aimanong\Tests\Unit\Fixtures\AppScopedResource;
use Aimanong\Tests\Unit\Fixtures\EverywhereResource;
use Aimanong\Tests\Unit\Fixtures\FakeApplicationResolver;
use Aimanong\Tests\Unit\Fixtures\FakeFrameworkRuntime;
use Aimanong\Tests\Unit\Fixtures\UserOnlyResource;
use Illuminate\Foundation\Auth\User;
use PHPUnit\Framework\TestCase;

/**
 * 多应用（多后台）的菜单隔离。
 *
 * ## 为什么这个测试必须存在
 *
 * 多应用的「骨架」（路由前缀 / 守卫 / 用户模型）早就有了，
 * 但**菜单与权限**一直没有应用维度：
 *
 *   - `MenuItem` 没有应用字段 → 所有 Resource 的菜单进同一个池子
 *   - 于是**用户后台会看到「管理员账号」「操作日志」** —— 而菜单是入口，
 *     串台等于把后台结构暴露给了不该看的人。
 *
 * 这个测试盯的是后者：**同一个 Resource 池，在不同后台应产出不同菜单。**
 *
 * ## 单应用必须零影响
 *
 * 未注册解析器时 `Drivers::application()` 回退 `SingleApplication`，
 * 所有 Resource 都属于它 → 单应用行为完全不变（见 test_single_app_unchanged）。
 */
class MultiApplicationMenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Drivers::reset();

        // 权限判定器：RBAC 未启用时放行，隔离由归属决定
        Drivers::registerPermissionChecker(new PermissionGateChecker);

        // 公开入口需要运行时（单测起不了 Laravel 容器，用假实现）
        Aimanong::bindRuntime(new FakeFrameworkRuntime);

        $registry = Aimanong::registry();
        $registry->register(AppScopedResource::class);   // admin, agent
        $registry->register(UserOnlyResource::class);    // user
        $registry->register(EverywhereResource::class);  // 所有后台
    }

    protected function tearDown(): void
    {
        /*
         * ⚠️ 必须把驱动**恢复成 bootstrap 那一套**，不能只 reset。
         *
         * 单测共享进程，其它用例（如 SchemaIntrospectorTest）依赖
         * bootstrap 注册的 introspector / dictionary 等。
         * 这里若只 reset，后面的用例会拿到 NullIntrospector 而红 ——
         * 而且红的位置离原因很远（第一次就是这样：SchemaIntrospectorTest 挂了）。
         */
        Drivers::reset();
        Drivers::registerIntrospector(new EloquentIntrospector);
        Drivers::registerDictionary(new Dictionary);
        Drivers::registerPermissionChecker(new PermissionGateChecker);
        Drivers::registerUploadSpec(new Uploader);

        parent::tearDown();
    }

    /**
     * 单应用（未注册解析器）：三个 Resource 全部可见 —— 与改动前一致。
     */
    public function test_single_app_unchanged(): void
    {
        $this->assertInstanceOf(SingleApplication::class, Drivers::application());
        $this->assertSame('default', Drivers::application()->current());

        $uris = $this->visibleUris();

        $this->assertContains('app-scoped', $uris);
        $this->assertContains('user-only', $uris);
        $this->assertContains('everywhere', $uris);
    }

    /**
     * 总后台：能看到「限定后台」与「所有后台」，看不到「仅用户后台」。
     */
    public function test_admin_app_menu(): void
    {
        Drivers::registerApplicationResolver((new FakeApplicationResolver)->withCurrent('admin'));

        $uris = $this->visibleUris();

        $this->assertContains('app-scoped', $uris);
        $this->assertContains('everywhere', $uris);
        $this->assertNotContains('user-only', $uris, '用户后台专属的菜单不应出现在总后台');
    }

    /**
     * 用户后台：只能看到自己专属的与通用的。
     */
    public function test_user_app_menu(): void
    {
        Drivers::registerApplicationResolver((new FakeApplicationResolver)->withCurrent('user'));

        $uris = $this->visibleUris();

        $this->assertContains('user-only', $uris);
        $this->assertContains('everywhere', $uris);
        $this->assertNotContains('app-scoped', $uris, '管理类菜单不应出现在用户后台');
    }

    /**
     * 代理商后台：与总后台共享「限定后台」，但不能看到用户专属的。
     */
    public function test_agent_app_menu(): void
    {
        Drivers::registerApplicationResolver((new FakeApplicationResolver)->withCurrent('agent'));

        $uris = $this->visibleUris();

        $this->assertContains('app-scoped', $uris);
        $this->assertContains('everywhere', $uris);
        $this->assertNotContains('user-only', $uris);
    }

    /**
     * Registry.belongsTo 的语义：未声明归属 = 属于所有后台。
     */
    public function test_belongs_to_semantics(): void
    {
        $registry = Aimanong::registry();

        $this->assertTrue($registry->belongsTo('everywhere', 'any-app'), '未声明归属应属于所有后台');
        $this->assertTrue($registry->belongsTo('app-scoped', 'admin'));
        $this->assertFalse($registry->belongsTo('app-scoped', 'user'));
        $this->assertTrue($registry->belongsTo('user-only', 'user'));
        $this->assertFalse($registry->belongsTo('user-only', 'admin'));
    }

    /**
     * 注册时显式指定归属，应覆盖 Resource::applications()。
     */
    public function test_register_with_explicit_applications(): void
    {
        $registry = Aimanong::registry();
        $registry->register(AppScopedResource::class, ['user']);

        $this->assertSame(['user'], $registry->applicationsFor('app-scoped'));
        $this->assertTrue($registry->belongsTo('app-scoped', 'user'));
        $this->assertFalse($registry->belongsTo('app-scoped', 'admin'), '显式指定应覆盖声明');
    }

    /**
     * `@primary` 占位符解析成**第一个**应用，而不是写死的 'admin'。
     *
     * 为什么这条重要：框架内置的系统管理类 Resource 声明
     * `[ApplicationResolver::PRIMARY]`。若解析成写死的 'admin'，
     * 把应用命名为 `sys` / `merchant` 的用户会发现
     * 管理员/角色/日志/字典页**在任何后台都看不见** —— 静默消失。
     */
    public function test_primary_placeholder_resolves_to_first_app(): void
    {
        // 主后台叫 sys（不叫 admin）
        $apps = new FakeApplicationResolver('sys', [
            'sys' => ['prefix' => 'sys', 'guard' => 'sys', 'model' => User::class, 'title' => '系统后台'],
            'agent' => ['prefix' => 'agent', 'guard' => 'agent', 'model' => User::class, 'title' => '代理商'],
        ]);

        Drivers::registerApplicationResolver($apps);

        $registry = Aimanong::registry();
        $registry->register(EverywhereResource::class, [ApplicationResolver::PRIMARY]);

        $this->assertTrue($registry->belongsTo('everywhere', 'sys'), '主后台应能看到 @primary 的 Resource');
        $this->assertFalse($registry->belongsTo('everywhere', 'agent'), '非主后台不应看到');
    }

    /**
     * 内置 Resource 真的是「只属于主后台」—— 主后台改名了也成立。
     */
    public function test_builtin_resources_follow_primary(): void
    {
        $apps = new FakeApplicationResolver('ops', [
            'ops' => ['prefix' => 'ops', 'guard' => 'ops', 'model' => User::class, 'title' => '运营'],
            'agent' => ['prefix' => 'agent', 'guard' => 'agent', 'model' => User::class, 'title' => '代理商'],
        ]);

        Drivers::registerApplicationResolver($apps);

        // 声明用的是 PRIMARY 占位符，而不是字面量 'admin'
        $this->assertSame(
            [ApplicationResolver::PRIMARY],
            AdminUserResource::applications()
        );

        $registry = Aimanong::registry();
        $registry->register(AdminUserResource::class);

        $this->assertTrue($registry->belongsTo('admin-users', 'ops'));
        $this->assertFalse($registry->belongsTo('admin-users', 'agent'));
    }

    /**
     * @return array<int, string>
     */
    protected function visibleUris(): array
    {
        $out = [];

        foreach ((new MenuRegistry)->visible() as $item) {
            $out[] = $item->uri;
        }

        return $out;
    }
}
