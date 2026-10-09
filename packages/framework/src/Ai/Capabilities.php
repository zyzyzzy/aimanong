<?php

declare(strict_types=1);

namespace Aimanong\Ai;

use Aimanong\Aimanong;
use Aimanong\Form\Fields\Field;
use Aimanong\Schema\Compiler;
use Aimanong\Support\FieldType;

/**
 * 能力清单。
 *
 * 这是 AI 的"记忆体"：框架能完整回答"我有什么能力"，
 * 而不只是"我有哪些类"。AI 不需要猜 API，直接读这个。
 */
class Capabilities
{
    /**
     * 完整能力清单。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => Aimanong::version(),
            'framework' => [
                'name' => 'Aimanong',
                'label' => 'AI 码农',
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
            ],
            'resources' => $this->resources(),
            'field_types' => $this->fieldTypes(),
            'column_options' => $this->columnOptions(),
            'form_options' => $this->formOptions(),
            'rules' => $this->availableRules(),
        ];
    }

    /**
     * 所有已注册 Resource 及其字段。
     *
     * @return array<int, array<string, mixed>>
     */
    public function resources(): array
    {
        $compiler = new Compiler;
        $out = [];

        foreach (Aimanong::registry()->all() as $uri => $class) {
            $node = $compiler->compile($class);

            $out[] = [
                'uri' => $node->uri,
                'label' => $node->label,
                'model' => $node->model,
                'resource_class' => $class,
                'grid' => [
                    'searchable' => array_values(array_map(
                        fn ($c): string => $c->name,
                        array_filter($node->columns, fn ($c): bool => $c->searchable)
                    )),
                    'sortable' => array_values(array_map(
                        fn ($c): string => $c->name,
                        array_filter($node->columns, fn ($c): bool => $c->sortable)
                    )),
                    'columns' => array_map(fn ($c): array => $c->toArray(), $node->columns),
                ],
                'form' => [
                    'fields' => array_map(fn ($f): array => $f->toArray(), $node->fields),
                    'rules' => $node->meta['rules'] ?? [],
                ],
            ];
        }

        return $out;
    }

    /**
     * 全部字段类型及其 PHP 类与可用属性。
     *
     * @return array<string, mixed>
     */
    public function fieldTypes(): array
    {
        $out = [];

        foreach (FieldType::all() as $type) {
            $class = 'Aimanong\\Form\\Fields\\'.$this->classOf($type);

            $out[$type] = [
                'php' => $class,
                'json_type' => FieldType::toJsonType($type),
                'props' => $this->propsOf($class),
            ];
        }

        return $out;
    }

    /**
     * 列可用选项。
     *
     * @return array<string, string>
     */
    public function columnOptions(): array
    {
        return [
            // 行为类
            'sortable' => '允许排序',
            'searchable' => '加入快捷搜索',
            'filter' => '加入筛选器',

            // 展示器：决定单元格如何渲染
            'dateTime' => '日期时间格式化，参数: 格式字符串（默认 Y-m-d H:i:s）',
            'bool' => '布尔→是否标签，参数: (trueLabel, falseLabel)，默认「是/否」',
            'badge' => '徽章样式（适合状态类字段）',
            'money' => '金额千分位，参数: 货币符号（默认 ¥）',
            'image' => '图片缩略图，参数: 高度像素（默认 32）',
            'link' => '超链接，参数: 显示文本',
            'progress' => '进度条（适合百分比/完成度）',
            'using' => '枚举值→标签映射，参数: 枚举类名',
            'map' => '值映射，参数: 关联数组',

            // 外观类
            'width' => '列宽，参数: 像素',
            'label' => '列标题，参数: 字符串',
        ];
    }

    /**
     * 表单可用选项。
     *
     * @return array<string, string>
     */
    public function formOptions(): array
    {
        return [
            'label' => '字段标签，参数: 字符串',
            'required' => '必填（自动生成 required 规则）',
            'default' => '默认值，参数: mixed',
            'readonly' => '只读',
            'hidden' => '隐藏',
            'rules' => '追加 Laravel 验证规则，参数: string|array',
            'max' => '最大长度，参数: int（生成 max:N）',
            'min' => '最小长度，参数: int（生成 min:N）',
            'placeholder' => '占位文本，参数: string',
            'help' => '帮助文本，参数: string',
            'options' => '下拉选项，参数: array 或 Enum::cases()',
            'rows' => '文本域行数，参数: int',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function availableRules(): array
    {
        return [
            'required', 'nullable', 'string', 'integer', 'numeric',
            'boolean', 'email', 'url', 'date', 'array',
            'min:N', 'max:N', 'between:min,max', 'in:a,b,c',
            'unique:table,column', 'exists:table,column',
        ];
    }

    protected function classOf(string $type): string
    {
        return match ($type) {
            'switch' => 'SwitchField',
            default => ucfirst($type),
        };
    }

    /**
     * 反射取字段类的链式方法（排除基类通用方法后的特有方法）。
     *
     * @return array<int, string>
     */
    protected function propsOf(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $base = new \ReflectionClass(Field::class);
        $baseMethods = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            $base->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        $own = [];
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (in_array($m->getName(), $baseMethods, true) || str_starts_with($m->getName(), '__')) {
                continue;
            }

            $own[] = $m->getName();
        }

        return $own;
    }
}
