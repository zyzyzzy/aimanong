<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Grid\Grid;
use PHPUnit\Framework\TestCase;

/**
 * 关联列与条件高亮测试。
 *
 * 这两个能力是**真实业务场景验证**暴露出来的 ——
 * 前五轮 AI 实测（单表 CRUD）完全没发现这类需求：
 *   - 商品列表要显示"分类名"而非 category_id
 *   - 库存少于 10 要标红
 */
class RelationAndHighlightTest extends TestCase
{
    public function test_relation_column_via_dot_notation(): void
    {
        $grid = new Grid;
        $column = $grid->column('category.name', '所属分类');

        // isRelation()/relationPath() 定义在声明层 Column 上
        $this->assertTrue($column->isRelation(), '点号命名的列应识别为关联列');
        $this->assertSame('category.name', $column->relationPath());
        $this->assertSame('category.name', $grid->toNodes()[0]->name);
    }

    public function test_relation_column_via_explicit_method(): void
    {
        $grid = new Grid;
        $column = $grid->column('cat', '分类')->relation('category.name');

        $this->assertTrue($column->isRelation());
        $this->assertSame('category.name', $column->relationPath());
    }

    public function test_plain_column_is_not_relation(): void
    {
        $grid = new Grid;

        $this->assertFalse($grid->column('name', '名称')->isRelation());
    }

    public function test_highlight_compiles_to_props(): void
    {
        $grid = new Grid;
        $grid->column('stock', '库存')->dangerWhen('<', 10, 'danger');

        $props = $grid->toNodes()[0]->props;

        $this->assertSame(
            ['operator' => '<', 'value' => 10, 'level' => 'danger'],
            $props['highlight'] ?? null
        );
    }

    public function test_danger_below_shorthand(): void
    {
        $grid = new Grid;
        $grid->column('stock', '库存')->dangerBelow(10);

        $hl = $grid->toNodes()[0]->props['highlight'];

        $this->assertSame('<', $hl['operator']);
        $this->assertSame(10, $hl['value']);
    }

    public function test_warning_above_shorthand(): void
    {
        $grid = new Grid;
        $grid->column('sold', '销量')->warningAbove(100);

        $hl = $grid->toNodes()[0]->props['highlight'];

        $this->assertSame('>', $hl['operator']);
        $this->assertSame('warning', $hl['level']);
    }

    /**
     * 关联列的 props 必须能被 JSON 序列化（前端要消费）。
     */
    public function test_relation_and_highlight_are_serializable(): void
    {
        $grid = new Grid;
        $grid->column('stock', '库存')->dangerBelow(10);
        $grid->column('category.name', '分类');

        $json = json_encode($grid->toArray());

        $this->assertIsString($json);
        $this->assertStringContainsString('highlight', (string) $json);
    }
}
