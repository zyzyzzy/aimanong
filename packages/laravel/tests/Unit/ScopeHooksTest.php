<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\ScopeHooks;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\TestCase;

/**
 * 数据作用域钩子测试。
 *
 * 多租户场景验证发现：框架对多租户**零支持**，
 * 且默认姿势相反（Resource 一注册就全表裸奔），
 * 框架不提供任何「收窄查询」的官方位置。
 *
 * 本类提供官方注册点 + 可自省元信息，
 * 把「不可见的业务约定」变成「框架能感知的声明」。
 */
class ScopeHooksTest extends TestCase
{
    protected function setUp(): void
    {
        ScopeHooks::flush();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        ScopeHooks::flush();
        parent::tearDown();
    }

    public function test_no_scope_by_default(): void
    {
        $this->assertFalse(ScopeHooks::hasQueryScope());
        $this->assertSame([], ScopeHooks::scopeNames());
    }

    public function test_register_query_scope(): void
    {
        ScopeHooks::query('tenant', function ($q): void {});

        $this->assertTrue(ScopeHooks::hasQueryScope());
        $this->assertSame(['tenant'], ScopeHooks::scopeNames());
    }

    public function test_introspect_warns_when_unconfigured(): void
    {
        $info = ScopeHooks::introspect();

        $this->assertFalse($info['configured']);
        $this->assertNotNull($info['warning'], '未配置时必须给出警告');
        $this->assertStringContainsString('全表', $info['warning']);
    }

    public function test_introspect_no_warning_when_configured(): void
    {
        ScopeHooks::query('tenant', function ($q): void {});

        $info = ScopeHooks::introspect();

        $this->assertTrue($info['configured']);
        $this->assertNull($info['warning']);
    }

    public function test_write_hook_applies_to_data(): void
    {
        ScopeHooks::writing('tenant', function (array $data): array {
            $data['tenant_id'] = 2;

            return $data;
        });

        $result = ScopeHooks::applyWriting(['name' => 'x']);

        $this->assertSame(2, $result['tenant_id']);
        $this->assertSame('x', $result['name']);
    }

    public function test_query_scope_is_applied(): void
    {
        $applied = false;

        ScopeHooks::query('probe', function ($q) use (&$applied): void {
            $applied = true;
        });

        // 用一个真实的 Builder 触发
        $query = new class extends Builder
        {
            public function __construct()
            {
                // 仅用于传递类型，不连接数据库
            }
        };

        ScopeHooks::applyQuery($query);

        $this->assertTrue($applied, '已注册的作用域必须被应用');
    }
}
