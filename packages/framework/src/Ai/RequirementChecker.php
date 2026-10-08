<?php

declare(strict_types=1);

namespace Aimanong\Ai;

use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Schema\Compiler;

/**
 * 需求达标度校验。
 *
 * 解决的问题：validate_declaration 原先只查「语法合法 / 能编译」，
 * 产出缺需求也会全绿，AI 会把绿灯当交付标准。
 *
 * 现在 AI 可提交需求清单，验证器逐条核对，明确回报
 * "哪些需求没被满足"，让 AI 知道自己是否真的完成了任务。
 */
class RequirementChecker
{
    /**
     * 校验需求清单。
     *
     * @param  array<string, mixed>  $requirements
     * @return array<string, mixed>
     */
    public function check(string $class, array $requirements): array
    {
        if (! class_exists($class) || ! is_subclass_of($class, \Aimanong\Resource::class)) {
            return [
                'checked' => false,
                'message' => '类不存在或不是 Resource，无法核对需求',
                'results' => [],
            ];
        }

        try {
            $node = (new Compiler())->compile($class);
        } catch (\Throwable $e) {
            return [
                'checked' => false,
                'message' => '编译失败，无法核对需求: '.$e->getMessage(),
                'results' => [],
            ];
        }

        $results = [];

        // 可搜索列
        if (isset($requirements['searchable'])) {
            $results[] = $this->checkColumns(
                'searchable',
                '可搜索',
                $this->names($requirements['searchable']),
                $this->flagged($node, 'searchable')
            );
        }

        // 可排序列
        if (isset($requirements['sortable'])) {
            $results[] = $this->checkColumns(
                'sortable',
                '可排序',
                $this->names($requirements['sortable']),
                $this->flagged($node, 'sortable')
            );
        }

        // 必填字段
        if (isset($requirements['required'])) {
            $required = [];

            foreach ($node->fields as $f) {
                if ($f->required) {
                    $required[] = $f->name;
                }
            }

            $results[] = $this->checkColumns(
                'required',
                '必填',
                $this->names($requirements['required']),
                $required
            );
        }

        // 必须存在的列
        if (isset($requirements['columns'])) {
            $existing = array_map(fn ($c): string => $c->name, $node->columns);
            $results[] = $this->checkColumns(
                'columns',
                '列表包含列',
                $this->names($requirements['columns']),
                $existing
            );
        }

        // 必须存在的表单字段
        if (isset($requirements['fields'])) {
            $existing = array_map(fn ($f): string => $f->name, $node->fields);
            $results[] = $this->checkColumns(
                'fields',
                '表单包含字段',
                $this->names($requirements['fields']),
                $existing
            );
        }

        // 每页条数
        if (isset($requirements['per_page'])) {
            $actual = $node->meta['perPage'] ?? 20;
            $expected = (int) $requirements['per_page'];
            $ok = $actual === $expected;

            $results[] = [
                'requirement' => "每页 {$expected} 条",
                'satisfied' => $ok,
                'detail' => $ok ? "已设置" : "实际为 {$actual} 条",
                'fix' => $ok ? null : "\$grid->perPage({$expected});",
            ];
        }

        $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['satisfied']));

        return [
            'checked' => true,
            'total' => count($results),
            'satisfied' => count($results) - count($failed),
            'failed' => count($failed),
            'all_satisfied' => $failed === [],
            'results' => $results,
            'message' => $failed === []
                ? '✅ 全部需求已满足'
                : sprintf('⚠️ %d 项需求未满足 —— 请修正后再交付', count($failed)),
        ];
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $actual
     * @return array<string, mixed>
     */
    protected function checkColumns(string $key, string $label, array $expected, array $actual): array
    {
        $missing = array_values(array_diff($expected, $actual));
        $ok = $missing === [];

        return [
            'requirement' => "{$label}: ".implode(', ', $expected),
            'satisfied' => $ok,
            'detail' => $ok
                ? '已满足'
                : '缺少 '.implode(', ', $missing).'（当前: '.implode(', ', $actual).'）',
            'fix' => $ok ? null : $this->fixHint($key, $missing),
        ];
    }

    /**
     * @param  array<int, string>  $missing
     */
    protected function fixHint(string $key, array $missing): string
    {
        $first = $missing[0] ?? 'field';

        return match ($key) {
            'searchable' => "\$grid->column('{$first}', '...')->searchable();",
            'sortable' => "\$grid->column('{$first}', '...')->sortable();",
            'required' => "\$form->text('{$first}')->label('...')->required();",
            'columns' => "\$grid->column('{$first}', '...');",
            'fields' => "\$form->text('{$first}')->label('...');",
            default => '',
        };
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    protected function flagged(ResourceNode $node, string $flag): array
    {
        $out = [];

        foreach ($node->columns as $c) {
            if ($flag === 'searchable' && $c->searchable) {
                $out[] = $c->name;
            }
            if ($flag === 'sortable' && $c->sortable) {
                $out[] = $c->name;
            }
        }

        return $out;
    }

    /**
     * 支持 "name,email" 字符串或数组。
     *
     * @return array<int, string>
     */
    protected function names(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn ($v): string => is_string($v) ? trim($v) : '',
                $value
            )));
        }

        return [];
    }
}
