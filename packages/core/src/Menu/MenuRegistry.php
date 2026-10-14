<?php

declare(strict_types=1);

namespace Aimanong\Menu;

use Aimanong\Aimanong;
use Aimanong\Support\Drivers;

/**
 * 菜单注册器。
 *
 * ## 设计
 *
 * 菜单由 Resource 的 `menu()` 声明生成（可选，不实现也能用）。
 * 声明示例：
 *
 * ```php
 * public static function menu(): array
 * {
 *     return ['group' => '内容管理', 'icon' => '📄', 'sort' => 10];
 * }
 * ```
 *
 * ## 关键行为
 *
 * 1. **按权限过滤**：RBAC 开启时，只显示当前用户有 `index` 权限的菜单
 * 2. **分组**：有 group 的归入分组，无 group 的直接放顶层
 * 3. **排序**：按 sort 升序，同 sort 按 label
 * 4. **可自省**：`tree()` 供 AI 与前端消费
 */
class MenuRegistry
{
    /**
     * 全部菜单项（未过滤）。
     *
     * @return array<int, MenuItem>
     */
    public function all(): array
    {
        $items = [];

        foreach (Aimanong::registry()->all() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            // menu() 是可选方法 —— 基类提供默认实现
            if (! method_exists($class, 'menu')) {
                continue;
            }

            /** @var mixed $decl */
            $decl = $class::menu();

            if (! is_array($decl)) {
                continue;
            }

            try {
                $item = MenuItem::fromDeclaration($class, $decl);
            } catch (\Throwable) {
                continue;
            }

            if (! $item->visible) {
                continue;
            }

            $items[] = $item;
        }

        return $this->sort($items);
    }

    /**
     * 当前用户可见的菜单（按权限过滤）。
     *
     * @return array<int, MenuItem>
     */
    public function visible(): array
    {
        $filtered = [];

        // 权限驱动只取一次：它不是无状态猜测，而是「当前登录用户的判定器」
        $gate = Drivers::permissions();

        /*
         * 当前后台（应用）。
         *
         * ⚠️ 这一层是**菜单隔离**的关键：不按应用过滤的话，
         * 用户后台会看到「管理员账号」「操作日志」这类本该只属于总后台的菜单 ——
         * 菜单是入口，串台等于把后台结构暴露给了不该看的人。
         *
         * ⚠️ 但**只在多应用开启时才过滤**。
         *
         * 单应用（enabled() === false）时不过滤，一律放行。
         * 否则一个声明了 `applications(['admin', 'agent'])` 的 Resource
         * 在单应用项目里会**凭空消失** —— 那里根本没有 admin/agent 之分。
         * （这个 bug 是测试抓到的：单应用回归断言立刻红了。）
         */
        $apps = Drivers::application();
        $filterByApp = $apps->enabled();
        $currentApp = $apps->current();

        foreach ($this->all() as $item) {
            if ($filterByApp && ! Aimanong::registry()->belongsTo($item->uri, $currentApp)) {
                continue;
            }

            $slug = $gate->slug($item->uri, 'index');

            if (! $gate->check($slug)) {
                continue;
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    /**
     * 菜单树（分组结构），供前端与 AI 消费。
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(): array
    {
        $groups = [];
        $flat = [];

        foreach ($this->visible() as $item) {
            if ($item->group !== '') {
                $groups[$item->group][] = $item->toArray();

                continue;
            }

            $flat[] = $item->toArray();
        }

        $out = [];

        // 无分组的直接放顶层
        foreach ($flat as $row) {
            $out[] = $row;
        }

        // 分组项
        foreach ($groups as $name => $children) {
            /** @var array<int, string> $sorts */
            $sorts = array_column($children, 'sort');
            $minSort = $sorts !== [] ? min($sorts) : 100;

            $out[] = [
                'label' => $name,
                'isGroup' => true,
                'sort' => $minSort,
                'children' => $children,
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($a['sort'] <=> $b['sort']) ?: strcmp((string) $a['label'], (string) $b['label']));

        return $out;
    }

    /**
     * 排序：sort 升序，同 sort 按 label。
     *
     * @param  array<int, MenuItem>  $items
     * @return array<int, MenuItem>
     */
    protected function sort(array $items): array
    {
        usort($items, static fn (MenuItem $a, MenuItem $b): int => ($a->sort <=> $b->sort) ?: strcmp($a->label, $b->label));

        return $items;
    }
}
