<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Contracts\SchemaIntrospector;
use Aimanong\Drivers\Eloquent\EloquentIntrospector;
use Aimanong\Exceptions\GhostColumnException;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Introspector\IntrospectorResolver;
use Aimanong\Schema\Introspector\NullIntrospector;
use Aimanong\Tests\Unit\Fixtures\FakeIntrospector;
use Aimanong\Tests\Unit\Fixtures\GhostColumnResource;
use PHPUnit\Framework\TestCase;

/**
 * 内省契约的接线测试。
 *
 * ## 这个文件补的是什么
 *
 * 幽灵列检测（宪法第三条「零隐式」的主要执行者）此前把 Eloquent 类型
 * 写死在 `Compiler` 里，且**数据库路径零测试覆盖** ——
 * 所有单测都因「无 DB → 跳过」而早退，184 个测试全绿也说明不了问题。
 *
 * 抽出 `SchemaIntrospector` 后，检测逻辑第一次可以脱离数据库被完整测试。
 */
class SchemaIntrospectorTest extends TestCase
{
    /** @return array<string, array<int, string>> */
    private function tables(): array
    {
        return [
            'ghost_columns' => ['id', 'title', 'created_at'],
        ];
    }

    public function test_ghost_column_is_detected_through_contract(): void
    {
        $introspector = new FakeIntrospector(
            tables: $this->tables(),
            table: 'ghost_columns',
        );

        try {
            (new Compiler($introspector))->compile(GhostColumnResource::class);
            $this->fail('拼错的列名 titel 应当抛 GhostColumnException，但没有');
        } catch (GhostColumnException $e) {
            $ctx = $e->context();

            $this->assertContains('column:titel', $ctx['ghost_columns']);
            $this->assertContains('title', $ctx['available_columns']);
            // AI 面向的自愈信息：改一次再试（宪法第六条）
            $this->assertSame('title', $ctx['did_you_mean']);
        }
    }

    /**
     * 虚拟属性必须放行 —— 这是「检测不误报」的一侧，
     * 也是换 ORM 后最容易静默失效的一侧。
     */
    public function test_virtual_attribute_is_not_reported_as_ghost(): void
    {
        $introspector = new FakeIntrospector(
            tables: $this->tables(),
            virtual: ['titel'],
            table: 'ghost_columns',
        );

        $node = (new Compiler($introspector))->compile(GhostColumnResource::class);

        $this->assertSame('ghost-columns', $node->uri);
    }

    /**
     * 表不存在时跳过检测（测试环境常见）。
     */
    public function test_detection_is_skipped_when_table_is_absent(): void
    {
        $introspector = new FakeIntrospector(
            tables: [],            // 一张表都没有
            table: 'ghost_columns',
        );

        $node = (new Compiler($introspector))->compile(GhostColumnResource::class);

        $this->assertSame('ghost-columns', $node->uri);
    }

    /**
     * 无驱动可用时，检测**静默关闭**。
     *
     * 这不是「正确行为」，是拆包前的既有行为 —— 本测试把它钉住，
     * 免得将来改成「报错」时没人注意到。
     * 计划：拆包完成后应改为抛明确错误（见拆包实施计划第 4 段）。
     */
    public function test_null_introspector_silently_disables_detection(): void
    {
        $node = (new Compiler(new NullIntrospector))->compile(GhostColumnResource::class);

        $this->assertSame('ghost-columns', $node->uri);
    }

    public function test_resolver_honours_explicit_introspector(): void
    {
        $explicit = new FakeIntrospector;

        $this->assertSame($explicit, IntrospectorResolver::resolve($explicit));
    }

    /**
     * 驱动已注册时，未显式注入的 Compiler 必须用上它 ——
     * 否则幽灵列检测会静默失效，而这是框架最不能降级的能力。
     *
     * 注册由 `tests/bootstrap.php` 完成（与真实应用的 ServiceProvider 同一套）。
     */
    public function test_resolver_picks_registered_driver(): void
    {
        $this->assertInstanceOf(EloquentIntrospector::class, IntrospectorResolver::resolve());
    }

    public function test_resolver_returns_contract(): void
    {
        $this->assertInstanceOf(SchemaIntrospector::class, IntrospectorResolver::resolve());
    }

    /**
     * 未显式注入时，Compiler 自己解析驱动（保证所有 `new Compiler` 调用点不变）。
     */
    public function test_compiler_without_argument_still_works(): void
    {
        $node = (new Compiler)->compile(GhostColumnResource::class);

        $this->assertSame('ghost-columns', $node->uri);
    }
}
