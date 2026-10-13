<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Services\TreeBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 树形结构测试。
 *
 * 重点覆盖循环引用检测 —— 这是树结构最危险的问题，
 * 会导致无限递归。
 */
class TreeBuilderTest extends TestCase
{
    protected const CFG = [
        'parentColumn' => 'parent_id',
        'orderColumn' => 'sort',
        'idColumn' => 'id',
    ];

    protected function rows(): array
    {
        return [
            ['id' => 1, 'parent_id' => 0, 'sort' => 2, 'name' => '电子产品'],
            ['id' => 2, 'parent_id' => 0, 'sort' => 1, 'name' => '图书'],
            ['id' => 3, 'parent_id' => 1, 'sort' => 1, 'name' => '手机'],
            ['id' => 4, 'parent_id' => 1, 'sort' => 2, 'name' => '电脑'],
            ['id' => 5, 'parent_id' => 3, 'sort' => 1, 'name' => '智能手机'],
        ];
    }

    public function test_builds_nested_tree(): void
    {
        $tree = (new TreeBuilder)->build($this->rows(), self::CFG);

        $this->assertCount(2, $tree);
        $this->assertSame('图书', $tree[0]['name'], '应按 sort 排序');
        $this->assertSame('电子产品', $tree[1]['name']);
        $this->assertCount(2, $tree[1]['children']);

        $phone = $tree[1]['children'][0];
        $this->assertSame('手机', $phone['name']);
        $this->assertCount(1, $phone['children']);
        $this->assertSame('智能手机', $phone['children'][0]['name']);
    }

    public function test_orphan_nodes_become_roots(): void
    {
        // 父级不存在时应视为根节点，而不是丢失
        $rows = [
            ['id' => 1, 'parent_id' => 999, 'sort' => 1, 'name' => '孤儿节点'],
        ];

        $tree = (new TreeBuilder)->build($rows, self::CFG);

        $this->assertCount(1, $tree);
        $this->assertSame('孤儿节点', $tree[0]['name']);
    }

    public function test_detects_two_node_cycle(): void
    {
        $rows = [
            ['id' => 1, 'parent_id' => 2, 'name' => 'A'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'B'],
        ];

        $cycles = (new TreeBuilder)->detectCycles($rows, self::CFG);

        $this->assertCount(1, $cycles, '双向引用必须被检出');
    }

    public function test_detects_three_node_cycle(): void
    {
        $rows = [
            ['id' => 1, 'parent_id' => 3, 'name' => 'A'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'B'],
            ['id' => 3, 'parent_id' => 2, 'name' => 'C'],
        ];

        $this->assertCount(1, (new TreeBuilder)->detectCycles($rows, self::CFG));
    }

    public function test_no_false_positive_on_valid_tree(): void
    {
        $this->assertSame([], (new TreeBuilder)->detectCycles($this->rows(), self::CFG));
    }

    public function test_cannot_move_node_under_its_own_descendant(): void
    {
        $r = (new TreeBuilder)->canMove($this->rows(), self::CFG, 1, 5);

        $this->assertFalse($r['ok'], '把父节点移到子孙下必须被拒绝');
        $this->assertStringContainsString('子孙', $r['reason'] ?? '');
    }

    public function test_cannot_move_node_under_itself(): void
    {
        $this->assertFalse((new TreeBuilder)->canMove($this->rows(), self::CFG, 3, 3)['ok']);
    }

    public function test_can_move_to_valid_parent(): void
    {
        $this->assertTrue((new TreeBuilder)->canMove($this->rows(), self::CFG, 5, 2)['ok']);
    }

    public function test_can_move_to_root(): void
    {
        $this->assertTrue((new TreeBuilder)->canMove($this->rows(), self::CFG, 5, null)['ok']);
    }
}
