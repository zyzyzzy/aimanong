<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Menu\MenuItem;
use Aimanong\Tests\Unit\Fixtures\MenuFakeResource;
use PHPUnit\Framework\TestCase;

/**
 * 菜单系统测试。
 *
 * 设计：Resource 可选覆盖 menu() 声明分组/图标/排序，
 * 不覆盖时用默认（label 作菜单名）。声明式，AI 可读。
 */
class MenuTest extends TestCase
{
    public function test_from_declaration_with_full_keys(): void
    {
        $item = MenuItem::fromDeclaration(MenuFakeResource::class, [
            'label' => '文章',
            'group' => '内容管理',
            'icon' => '📝',
            'sort' => 10,
            'visible' => true,
        ]);

        // uri/label 走不了真实 Resource，这里只验声明的键
        $this->assertSame('内容管理', $item->group);
        $this->assertSame('📝', $item->icon);
        $this->assertSame(10, $item->sort);
        $this->assertTrue($item->visible);
    }

    public function test_missing_keys_fall_back(): void
    {
        $item = MenuItem::fromDeclaration(MenuFakeResource::class, []);

        $this->assertSame('', $item->group);
        $this->assertSame('', $item->icon);
        $this->assertSame(100, $item->sort);
        $this->assertTrue($item->visible);
    }

    public function test_invisible_declaration(): void
    {
        $item = MenuItem::fromDeclaration(MenuFakeResource::class, ['visible' => false]);

        $this->assertFalse($item->visible);
    }

    public function test_to_array_shape(): void
    {
        $item = MenuItem::fromDeclaration(MenuFakeResource::class, [
            'group' => '电商', 'icon' => '📦', 'sort' => 20,
        ]);

        $arr = $item->toArray();

        $this->assertSame(['uri', 'label', 'group', 'icon', 'sort'], array_keys($arr));
        $this->assertSame('电商', $arr['group']);
    }

    public function test_numeric_sort_coerced_to_int(): void
    {
        $item = MenuItem::fromDeclaration(MenuFakeResource::class, ['sort' => '15']);

        $this->assertSame(15, $item->sort);
    }
}
