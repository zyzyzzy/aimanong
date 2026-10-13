<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Support\FieldType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 产出 Resource 代码模板。
 *
 * 相比 scaffold_resource（从表结构生成），此工具根据
 * AI 指定的字段列表生成，适用于表尚未创建的场景。
 */
class CreatePage extends Tool
{
    protected string $description = <<<'MARKDOWN'
        按指定字段生成 Aimanong Resource 的 PHP 代码模板。

        若数据库表已存在，优先用 scaffold_resource（直接读表结构，不会猜错字段）。
        本工具适用于表尚未创建的场景。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $name = (string) $request->string('name');

        // string() 返回 Stringable，缺失时是空 Stringable 而非 null，
        // 用 ?: 判空会失效 —— 必须先转字符串再 trim 判断。
        $uri = trim((string) $request->string('uri'));
        $uri = $uri === '' ? Str::kebab(Str::plural($name)) : $uri;

        $label = trim((string) $request->string('label'));
        $label = $label === '' ? $name : $label;

        $model = trim((string) $request->string('model'));
        $model = $model === '' ? 'App\\Models\\'.Str::studly($name) : $model;

        $fields = $request->array('fields');

        $gridLines = ["        \$grid->column('id', 'ID')->sortable();"];
        $formLines = [];

        foreach ($fields as $f) {
            if (! is_array($f)) {
                continue;
            }

            $fieldName = (string) ($f['name'] ?? '');
            if ($fieldName === '') {
                continue;
            }

            $type = (string) ($f['type'] ?? 'text');

            if (! FieldType::exists($type)) {
                $suggestion = FieldType::suggest($type);

                return Response::text(sprintf(
                    "字段类型 '%s' 不存在。%s\n\n可用类型: %s",
                    $type,
                    $suggestion !== null ? "是否想用 '{$suggestion}'？" : '',
                    implode(', ', FieldType::all())
                ));
            }

            $fieldLabel = (string) ($f['label'] ?? $fieldName);
            $fieldLabel = $fieldLabel === '' ? $fieldName : $fieldLabel;

            $searchable = ! empty($f['searchable']);
            $required = ! empty($f['required']);

            $gridLines[] = $searchable
                ? "        \$grid->column('{$fieldName}', '{$fieldLabel}')->searchable();"
                : "        \$grid->column('{$fieldName}', '{$fieldLabel}');";

            $chain = "        \$form->{$type}('{$fieldName}')->label('{$fieldLabel}')";
            if ($required) {
                $chain .= '->required()';
            }
            if ($type === 'text') {
                $chain .= '->max(255)';
            }
            $formLines[] = $chain.';';
        }

        $gridLines[] = "        \$grid->column('created_at', '创建时间')->dateTime()->sortable();";
        $gridLines[] = "        \$grid->column('updated_at', '更新时间')->dateTime()->sortable();";

        $className = Str::studly($name).'Resource';
        $modelBase = class_basename($model);

        $grid = implode("\n", $gridLines);
        $form = implode("\n", $formLines);

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
                return {$modelBase}::class;
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
        {$grid}
            }

            public static function form(Form \$form): void
            {
        {$form}
            }

            public static function show(Show \$show): void
            {
                \$show->fields(['id', '{$uri}']);
            }
        }

        PHP;

        $code = str_replace('        <?php', '<?php', $code);

        return Response::text(sprintf(
            "已生成 %s 的代码（%d 个字段）。\n\n"
            ."保存为: app/Aimanong/%s.php\n"
            ."注册: Aimanong::registry()->register(App\\Aimanong\\%s::class);\n\n"
            ."下一步: validate_declaration 校验。\n\n"
            ."```php\n%s```",
            $label,
            count($fields),
            $className,
            $className,
            $code
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('资源名，英文单数，如 Article')
                ->required(),
            'uri' => $schema->string()
                ->description('URI，默认按 name 推导，如 articles'),
            'label' => $schema->string()
                ->description('中文名称，如 文章'),
            'model' => $schema->string()
                ->description('Eloquent 模型全限定类名'),
            'fields' => $schema->array()
                ->items(
                    // 注意：object() 通过构造参数接收属性，没有链式 properties()
                    $schema->object([
                        'name' => $schema->string()->description('字段名'),
                        'type' => $schema->string()->description('类型: '.implode(', ', FieldType::all())),
                        'label' => $schema->string()->description('中文标签'),
                        'required' => $schema->boolean()->description('是否必填'),
                        'searchable' => $schema->boolean()->description('是否加入快捷搜索'),
                    ])
                )
                ->description('字段列表')
                ->required(),
        ];
    }
}
