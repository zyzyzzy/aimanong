<?php

declare(strict_types=1);

namespace Aimanong\Services;

/**
 * 树结构构建与校验。
 *
 * 关键职责：
 *   1. 扁平数据 → 嵌套树
 *   2. **循环引用检测** —— 这是树结构最危险的问题：
 *      A 的父级是 B、B 的父级是 A，会导致无限递归
 *   3. 移动节点时的合法性校验（不能把父节点移到自己的子孙下）
 */
class TreeBuilder
{
    /**
     * 扁平数据组装为树。
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{parentColumn: string, orderColumn: string, idColumn: string}  $config
     * @return array<int, array<string, mixed>>
     */
    public function build(array $rows, array $config): array
    {
        $parentCol = $config['parentColumn'];
        $orderCol = $config['orderColumn'];
        $idCol = $config['idColumn'];

        $byId = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $byId[$row[$idCol]] = $row;
        }

        $roots = [];

        foreach ($byId as $id => $row) {
            $parent = $row[$parentCol] ?? null;

            // 父级为空、或父级不在集合中 → 视为根节点
            if ($parent === null || $parent === 0 || $parent === '0' || ! isset($byId[$parent])) {
                $roots[$id] = &$byId[$id];

                continue;
            }

            $byId[$parent]['children'][$id] = &$byId[$id];
        }

        // 排序：递归按 orderColumn 排
        $sortFn = function (array &$nodes) use (&$sortFn, $orderCol): void {
            uasort($nodes, fn (array $a, array $b): int => ($a[$orderCol] ?? 0) <=> ($b[$orderCol] ?? 0));

            foreach ($nodes as &$node) {
                if (! empty($node['children'])) {
                    $sortFn($node['children']);
                }
            }
        };

        $sortFn($roots);

        // 打破引用，避免后续操作产生意外联动
        return $this->dereference(array_values($roots));
    }

    /**
     * 检测循环引用。
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{parentColumn: string, idColumn: string}  $config
     * @return array<int, array<int|string>> 循环链列表（空数组表示无循环）
     */
    public function detectCycles(array $rows, array $config): array
    {
        $parentCol = $config['parentColumn'];
        $idCol = $config['idColumn'];

        $parentOf = [];
        foreach ($rows as $row) {
            $parentOf[$row[$idCol]] = $row[$parentCol] ?? null;
        }

        $cycles = [];
        $resolved = [];

        foreach (array_keys($parentOf) as $start) {
            if (isset($resolved[$start])) {
                continue;
            }

            $path = [];
            $seen = [];
            $cur = $start;

            while ($cur !== null && $cur !== 0 && $cur !== '0') {
                if (isset($seen[$cur])) {
                    // 找到环：截取从环起点到当前的路径
                    $at = array_search($cur, $path, true);

                    if ($at !== false) {
                        $cycles[] = array_slice($path, (int) $at);
                    }

                    break;
                }

                if (isset($resolved[$cur])) {
                    break;
                }

                $seen[$cur] = true;
                $path[] = $cur;
                $cur = $parentOf[$cur] ?? null;
            }

            foreach ($path as $p) {
                $resolved[$p] = true;
            }
        }

        return $cycles;
    }

    /**
     * 判断把 $nodeId 移到 $newParentId 下是否合法。
     *
     * 不能把节点移到自己的子孙下 —— 那会形成环。
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{parentColumn: string, idColumn: string}  $config
     * @return array{ok: bool, reason?: string}
     */
    public function canMove(array $rows, array $config, int|string $nodeId, int|string|null $newParentId): array
    {
        if ($newParentId === null) {
            return ['ok' => true];
        }

        if ((string) $nodeId === (string) $newParentId) {
            return ['ok' => false, 'reason' => '不能把节点移动到自身之下'];
        }

        $parentCol = $config['parentColumn'];
        $idCol = $config['idColumn'];

        $parentOf = [];
        foreach ($rows as $row) {
            $parentOf[$row[$idCol]] = $row[$parentCol] ?? null;
        }

        // 从目标父级向上追溯，若遇到 nodeId 则非法
        $cur = $newParentId;
        $guard = 0;

        while ($cur !== null && $cur !== 0 && $cur !== '0') {
            if ((string) $cur === (string) $nodeId) {
                return ['ok' => false, 'reason' => '不能把节点移动到自己的子孙之下（会形成循环）'];
            }

            $cur = $parentOf[$cur] ?? null;

            if (++$guard > 1000) {
                return ['ok' => false, 'reason' => '层级过深或存在既有循环，已中止'];
            }
        }

        return ['ok' => true];
    }

    /**
     * 递归解除引用，返回纯数组。
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    protected function dereference(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);

            $node['children'] = $this->dereference(array_values($children));

            $out[] = $node;
        }

        return $out;
    }
}
