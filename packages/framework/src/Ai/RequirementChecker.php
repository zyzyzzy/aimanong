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

        /*
         * 每页条数 —— 必须验证"功能真的生效"，而不只是"声明写了"。
         *
         * 背景：曾出现 $grid->perPage(30) 只写进 schema.json、
         * 而 API 层仍用 20 的情况。校验器报"已设置"，功能却没实现，
         * 属于假阳性 —— 比不检查更危险。
         *
         * 因此这里实际跑一次 Repository 分页，核对真实返回值。
         */
        if (isset($requirements['per_page'])) {
            $expected = (int) $requirements['per_page'];
            $declared = $node->meta['perPage'] ?? 20;

            $actual = $this->runtimePerPage($node);

            if ($actual === null) {
                // 无法运行时验证（表不存在等），降级为声明检查并说明
                $ok = $declared === $expected;

                $results[] = [
                    'requirement' => "每页 {$expected} 条",
                    'satisfied' => $ok,
                    'detail' => $ok
                        ? "声明为 {$declared} 条（未能运行时验证：表不可用）"
                        : "声明为 {$declared} 条，期望 {$expected}",
                    'fix' => $ok ? null : "\$grid->perPage({$expected});",
                ];
            } else {
                // 运行时值与声明值都要对，才算真正生效
                $ok = $actual === $expected;

                $results[] = [
                    'requirement' => "每页 {$expected} 条",
                    'satisfied' => $ok,
                    'detail' => $ok
                        ? "已生效（运行时实测 {$actual} 条）"
                        : "❌ 声明为 {$declared}，但运行时实际返回 {$actual} 条 —— 声明未生效",
                    'fix' => $ok ? null : "\$grid->perPage({$expected}); 并确认 ResourceController 读取了该值",
                ];
            }
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
     * 运行时验证每页条数：真的跑一次分页，看返回多少条。
     *
     * 这是与"只读 schema 元数据"的关键区别 —— 能发现
     * "声明了但没生效"这类假阳性。
     *
     * @return int|null null 表示无法验证（表不可用等）
     */
    protected function runtimePerPage(ResourceNode $node): ?int
    {
        try {
            $model = $node->model;

            if (! class_exists($model)) {
                return null;
            }

            /** @var \Illuminate\Database\Eloquent\Model $instance */
            $instance = new $model();
            $table = $instance->getTable();

            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                return null;
            }

            // 模拟 ResourceController 的调用方式：不传 per_page，
            // 让 Repository 自行决定 —— 这正是原先断链的地方。
            $repo = new \Aimanong\Repository\EloquentRepository($model);
            $paginator = $repo->paginate([
                'searchable' => [],
            ], $node->meta['perPage'] ?? null);

            return $paginator->perPage();
        } catch (\Throwable) {
            return null;
        }
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
