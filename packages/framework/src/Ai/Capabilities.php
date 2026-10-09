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
            'tree_options' => $this->treeOptions(),
            'extension' => $this->extensionCapabilities(),
            'requirement_keys' => $this->requirementKeys(),
            'applications' => $this->applicationCapabilities(),
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

            // 列表整体配置（写在 grid() 中，不属于任何单列）
            'perPage' => '每页条数，参数: int。写法: $grid->perPage(15); 不传则用框架默认 20',
            'actions' => '是否显示行操作按钮，参数: bool',
            'batchActions' => '批量操作按钮，参数: 数组',

            // 导出（写在 grid() 中）
            'export' => '开启 CSV 导出，写法: $grid->export(); 未开启时导出接口返回 403',
            'exportExcept' => '导出时排除的列，参数: 数组。如 exportExcept([\'cover_url\'])',
            'exportChunkSize' => '导出分批查询条数，参数: int（默认 1000，大表用）',
        ];
    }

    /**
     * 树形结构选项（在 Resource 的 tree() 方法中使用）。
     *
     * @return array<string, string>
     */
    public function treeOptions(): array
    {
        return [
            'parentColumn' => '父级字段名，参数: 字符串（默认 parent_id）',
            'titleColumn' => '节点显示字段，参数: 字符串（默认 name）',
            'orderColumn' => '排序字段，参数: 字符串（默认 sort）',
            'draggable' => '（尚未实现）声明允许拖拽。当前前端无拖拽 UI，调整层级请用编辑表单或 PUT /{uri}/{id}/move',
            'maxDepth' => '最大层级，参数: int（0 = 不限制）',
        ];
    }

    /**
     * validate_declaration 支持的 requirements 键。
     *
     * 明确列出，避免 AI 猜测或使用不会被核对的键。
     *
     * @return array<string, string>
     */
    public function requirementKeys(): array
    {
        return [
            'searchable' => '要求可搜索的列，逗号分隔',
            'sortable' => '要求可排序的列，逗号分隔',
            'required' => '要求必填的表单字段，逗号分隔',
            'columns' => '要求列表包含的列，逗号分隔',
            'fields' => '要求表单包含的字段，逗号分隔',
            'per_page' => '要求的每页条数（会运行时实测）',
            'tree' => '是否要求树形结构，传 true',
            'export' => '是否要求开启导出，传 true',
            'step' => '是否要求分步表单（至少 2 步），传 true',
        ];
    }

    /**
     * 扩展（插件）能力说明。
     *
     * @return array<string, string>
     */
    public function extensionCapabilities(): array
    {
        return [
            '继承' => 'App 的扩展类需继承 Aimanong\\Extend\\Extension',
            'name()' => '扩展唯一标识（必需）',
            'dependencies()' => '依赖的其他扩展名，缺失会被跳过而非崩溃',
            'register()' => '注册阶段：绑定容器、登记 Resource（此时勿访问数据库）',
            'boot()' => '启动阶段：注册路由、视图、菜单',
            'this->resources()' => '登记本扩展提供的 Resource',
            'this->routes()' => '注册路由（自动带后台前缀与中间件）',
            '启用方式' => "config/aimanong.php 的 'extensions' 数组",
            '生成骨架' => 'php artisan aimanong:make-extension {Name}',
            '查看状态' => 'php artisan aimanong:extensions',
        ];
    }

    /**
     * 多应用能力说明。
     *
     * @return array<string, string>
     */
    public function applicationCapabilities(): array
    {
        return [
            '配置' => "config/aimanong.php 的 'applications' 数组",
            '隔离维度' => '每个应用有独立的路由前缀、auth guard、用户模型',
            '当前应用' => 'Aimanong::application()->current()',
            '切换' => "Aimanong::application()->switch('merchant')",
            '实现' => '用 Laravel 12 的 Context 做请求级隔离（并发安全）',
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
            'step' => '声明分步表单的步骤，参数: 字符串（步骤标题）。后续字段归属该步骤',
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
