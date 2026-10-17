<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Introspection;
use Aimanong\Aimanong;
use Aimanong\Layout\Layout;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Aimanong\Tests\Unit\Fixtures\FakeFrameworkRuntime;
use Aimanong\Tests\Unit\Fixtures\TabbedDetailResource;
use PHPUnit\Framework\TestCase;

/**
 * 详情页结构：`Resource::layout()`（tabs / sections / cards）。
 *
 * ## 这个能力解决什么
 *
 * `$show->fields([...])` 是一长串平铺。字段一多就读不出来谁跟谁一类，
 * 想分「基本信息 / 联系方式 / 财务」只能去写 Blade ——
 * 也就是「AI 必须关心前端实现细节」。
 *
 * `layout()` 给详情页一个**结构词表**。
 *
 * ## 两条必须钉住的边界
 *
 * 1. **没进分组的字段不能被吞掉** —— 加一个 layout() 反而少显示字段，
 *    是「配了更差」，最容易让人不信任声明
 * 2. **引用了不存在的字段必须报错** —— 否则页面上少一块而毫无提示
 */
class LayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Aimanong::bindRuntime(new FakeFrameworkRuntime);
    }

    /**
     * 未声明 layout() → kind = flat（平铺），与加这个能力之前一致。
     */
    public function test_unset_layout_is_flat(): void
    {
        $layout = new Layout;

        $this->assertTrue($layout->isEmpty());
        $this->assertSame(['kind' => 'flat', 'groups' => []], $layout->toArray());
    }

    /**
     * 三种容器都能声明并读回。
     */
    public function test_three_container_kinds(): void
    {
        foreach (['tabs' => 'tabs', 'sections' => 'sections', 'cards' => 'cards'] as $method => $kind) {
            $layout = new Layout;
            $layout->{$method}(['基本' => ['name']]);

            $this->assertSame($kind, $layout->getKind());
            $this->assertFalse($layout->isEmpty());
        }
    }

    public function test_groups_keep_declaration_order(): void
    {
        $layout = new Layout;
        $layout->tabs([
            '第一组' => ['a', 'b'],
            '第二组' => ['c'],
        ]);

        $groups = $layout->getGroups();

        $this->assertSame('第一组', $groups[0]['title']);
        $this->assertSame(['a', 'b'], $groups[0]['fields']);
        $this->assertSame('第二组', $groups[1]['title']);
    }

    /**
     * 🔴 边界 1：`show()` 里声明了、但没进任何分组的字段，
     * 必须**追加到最后一组**，不许静默消失。
     */
    public function test_fields_missing_from_groups_are_appended_not_dropped(): void
    {
        $layout = new Layout;
        $layout->tabs([
            '基本' => ['name'],
        ]);

        $out = $layout->toArray(['name', 'phone', 'created_at']);

        $fields = $out['groups'][0]['fields'];

        $this->assertContains('phone', $fields, '未分组的字段被吞掉了');
        $this->assertContains('created_at', $fields, '未分组的字段被吞掉了');
    }

    /**
     * 🔴 边界 2：引用不存在的字段 → 编译期报错（不是静默少显示一块）。
     */
    public function test_unknown_field_throws_with_available_list(): void
    {
        $layout = new Layout;
        $layout->tabs([
            '基本' => ['name', 'typo_field'],
        ]);

        try {
            $layout->toArray(['name', 'phone']);
            $this->fail('引用未知字段应该抛异常');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('typo_field', $e->getMessage());
            // 报错要给出可用的字段，AI 才知道该改成什么
            $this->assertStringContainsString('name', $e->getMessage());
            $this->assertStringContainsString('phone', $e->getMessage());
        }
    }

    /**
     * 空分组 / 空数组是笔误，必须报错 ——
     * 否则页面上会出现一个没有内容的标题。
     */
    public function test_empty_groups_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Layout)->tabs(['空组' => []]);
    }

    public function test_empty_group_list_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Layout)->tabs([]);
    }

    /**
     * 组装点 ①：Compiler 必须把 layout 写进 meta。
     */
    public function test_compiler_carries_layout_into_meta(): void
    {
        $node = (new Compiler)->compile(TabbedDetailResource::class);

        $this->assertArrayHasKey('layout', $node->meta);
        $this->assertSame('tabs', $node->meta['layout']['kind']);
        $this->assertCount(2, $node->meta['layout']['groups']);
    }

    /**
     * 组装点 ②：页面 schema 的 show 段要带 layout —— 前端靠它选结构分支。
     */
    public function test_page_schema_carries_layout(): void
    {
        $node = (new Compiler)->compile(TabbedDetailResource::class);
        $schema = (new JsonSchemaEmitter)->emit($node);

        $this->assertArrayHasKey('layout', $schema['show']);
        $this->assertSame('tabs', $schema['show']['layout']['kind']);
    }

    /**
     * 组装点 ③：自省接口也要带 —— 两处不一致会让 AI 判断错。
     */
    public function test_introspection_carries_layout(): void
    {
        Aimanong::registry()->register(TabbedDetailResource::class);

        $result = (new Introspection)->schema(TabbedDetailResource::uri());

        $this->assertSame(200, $result['status']);
        $this->assertSame('tabs', $result['body']['show']['layout']['kind']);
    }

    /**
     * 三者形状必须一致（不一致 = AI 自省到的与页面实际不符）。
     */
    public function test_layout_shape_is_identical_across_the_three_sites(): void
    {
        $class = TabbedDetailResource::class;
        Aimanong::registry()->register($class);

        $node = (new Compiler)->compile($class);
        $fromEmitter = (new JsonSchemaEmitter)->emit($node)['show']['layout'];
        $fromIntrospection = (new Introspection)->schema(TabbedDetailResource::uri())['body']['show']['layout'];

        $this->assertSame($node->meta['layout'], $fromEmitter);
        $this->assertSame($node->meta['layout'], $fromIntrospection);
    }

    /**
     * 前端必须真的渲染三种结构 —— 后端全对、前端不读等于没做。
     */
    public function test_frontend_renders_all_container_kinds(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/resource.blade.php'
        );

        foreach (['flat', 'tabs'] as $kind) {
            $this->assertMatchesRegularExpression(
                "/detailLayout\.kind === '{$kind}'/",
                $blade,
                "前端缺 {$kind} 结构分支"
            );
        }

        // sections / cards 走 v-else 兜底（两者共用同一套分块渲染）
        $this->assertStringContainsString('am-detail-section', $blade, '缺分块渲染');
        $this->assertStringContainsString('am-tabs__tab', $blade, '缺标签页渲染');
    }
}
