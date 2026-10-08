<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 从数据库表一键生成 Resource 代码。
 *
 * 这是 AI 最省事的路径：不用猜字段，直接读表结构生成。
 * 用 Laravel 12 的原生 Schema API（doctrine/dbal 已被移除）。
 */
class ScaffoldResource extends Tool
{
    protected string $description = <<<'MARKDOWN'
        从数据库表结构生成 Aimanong Resource 的 PHP 代码。
        推荐优先使用此工具：它直接读取真实表结构，不会猜错字段名。

        生成后仍需用 validate_declaration 校验，并把文件保存到 app/Aimanong/。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $table = (string) $request->string('table');
        $modelInput = $request->string('model');
        $model = ($modelInput !== null && (string) $modelInput !== '')
            ? (string) $modelInput
            : 'App\\Models\\'.Str::studly(Str::singular($table));

        try {
            $columns = Schema::getColumns($table);
        } catch (\Throwable $e) {
            return Response::text(sprintf(
                "读取表 [%s] 失败: %s\n\n提示：确认表存在且数据库连接正常。",
                $table,
                $e->getMessage()
            ));
        }

        if ($columns === []) {
            return Response::text("表 [{$table}] 没有字段，或表不存在。");
        }

        $className = Str::studly(Str::singular($table)).'Resource';
        $uri = Str::kebab(Str::plural($table));
        // 注意：string() 返回 Stringable 对象，?: 判断恒为 true，
        // 必须先转字符串再判空。
        $label = trim((string) $request->string('label'));
        $label = $label === '' ? $table : $label;

        $gridLines = [];
        $formLines = [];

        foreach ($columns as $col) {
            $name = $col['name'];

            if (in_array($name, ['id', 'created_at', 'updated_at'], true)) {
                $gridLines[] = $name === 'id'
                    ? "        \$grid->column('id', 'ID')->sortable();"
                    : "        \$grid->column('{$name}', '{$this->zh($name)}')->dateTime()->sortable();";

                continue;
            }

            if (str_ends_with($name, '_id')) {
                $gridLines[] = "        \$grid->column('{$name}', '{$this->zh($name)}')->sortable();";
                $formLines[] = "        \$form->number('{$name}')->label('{$this->zh($name)}');";

                continue;
            }

            $gridLines[] = in_array($name, ['name', 'title'], true)
                ? "        \$grid->column('{$name}', '{$this->zh($name)}')->searchable();"
                : "        \$grid->column('{$name}', '{$this->zh($name)}');";

            $formLines[] = $this->formLine($col, $name);
        }

        $showNames = array_slice(array_column($columns, 'name'), 0, 5);
        $showList = implode("', '", $showNames);

        $code = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Aimanong;

        use Aimanong\\Form\\Form;
        use Aimanong\\Grid\\Grid;
        use Aimanong\\Resource;
        use Aimanong\\Show\\Show;
        use {$model};

        class {$className} extends Resource
        {
            public static function model(): string
            {
                return {$this->classBasename($model)}::class;
            }

            public static function label(): string
            {
                return '{$label}';
            }

            public static function uri(): string
            {
                return '{$uri}';
            }

            public static function grid(Grid \$grid): void
            {
        {$this->join($gridLines)}
            }

            public static function form(Form \$form): void
            {
        {$this->join($formLines)}
            }

            public static function show(Show \$show): void
            {
                \$show->fields(['{$showList}']);
            }
        }

        PHP;

        $code = str_replace('        <?php', '<?php', $code);

        return Response::text(sprintf(
            "已根据表 [%s] 生成 Resource 代码（共 %d 个字段）。\n\n"
            ."保存为: app/Aimanong/%s.php\n"
            ."并在 ServiceProvider 中注册: Aimanong::registry()->register(App\\Aimanong\\%s::class);\n\n"
            ."然后运行 validate_declaration 校验。\n\n"
            ."```php\n%s```",
            $table,
            count($columns),
            $className,
            $className,
            $code
        ));
    }

    /**
     * @param  array<string, mixed>  $col
     */
    protected function formLine(array $col, string $name): string
    {
        $type = strtolower((string) ($col['type'] ?? 'string'));
        $label = $this->zh($name);
        $nullable = (bool) ($col['nullable'] ?? false);

        $required = $nullable ? '' : '->required()';

        $method = match (true) {
            str_contains($type, 'bool'), str_contains($type, 'tinyint') && str_ends_with($name, '_at') === false && str_contains($name, 'enable') || str_contains($name, 'status') && str_contains($type, 'int') => 'switch',
            str_contains($type, 'int') => 'number',
            str_contains($type, 'decimal'), str_contains($type, 'float'), str_contains($type, 'double') => 'decimal',
            str_contains($type, 'text') => 'textarea',
            str_contains($type, 'date'), str_contains($type, 'time') => 'date',
            default => 'text',
        };

        if ($name === 'email') {
            return "        \$form->email('{$name}')->label('{$label}'){$required}->rules('email');";
        }

        if ($method === 'switch') {
            return "        \$form->switch('{$name}')->label('{$label}');";
        }

        if ($method === 'text') {
            return "        \$form->text('{$name}')->label('{$label}'){$required}->max(255);";
        }

        return "        \$form->{$method}('{$name}')->label('{$label}'){$required};";
    }

    protected function zh(string $name): string
    {
        return [
            'name' => '名称',
            'title' => '标题',
            'email' => '邮箱',
            'phone' => '电话',
            'mobile' => '手机',
            'status' => '状态',
            'enabled' => '是否启用',
            'sort' => '排序',
            'remark' => '备注',
            'description' => '描述',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
        ][$name] ?? $name;
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function join(array $lines): string
    {
        return $lines === [] ? "        // 无字段" : implode("\n", $lines);
    }

    protected function classBasename(string $fqcn): string
    {
        return class_basename($fqcn);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->description('数据库表名，如 users')
                ->required(),
            'model' => $schema->string()
                ->description('Eloquent 模型全限定类名，默认按表名推导'),
            'label' => $schema->string()
                ->description('中文名称，默认用表名'),
        ];
    }
}
