<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Services\ResourceGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
        $table = trim((string) $request->string('table'));

        // string() 返回 Stringable（缺失时是空对象，永不为 null），
        // 必须转字符串再判空 —— 与 null 比较恒为 true。
        $modelInput = trim((string) $request->string('model'));
        $labelInput = trim((string) $request->string('label'));

        try {
            // 与 aimanong:make-resource 命令共用同一生成器，
            // 保证两条路径产出的代码完全一致（避免逻辑分叉）
            $result = (new ResourceGenerator)->generate(
                $table,
                $modelInput !== '' ? $modelInput : null,
                $labelInput !== '' ? $labelInput : null,
            );
        } catch (\Throwable $e) {
            return Response::text(sprintf(
                "生成失败: %s\n\n提示：确认表 [%s] 存在且数据库连接正常。",
                $e->getMessage(),
                $table
            ));
        }

        return Response::text(sprintf(
            "已根据表 [%s] 生成 Resource 代码（共 %d 个字段）。\n\n"
            ."保存为: app/Aimanong/%s.php\n"
            ."并在 ServiceProvider 中注册: Aimanong::registry()->register(App\\Aimanong\\%s::class);\n\n"
            ."或者直接用命令一步完成（含自动注册）：\n"
            ."  php artisan aimanong:make-resource %s --label='%s' --register\n\n"
            ."然后运行 validate_declaration 校验。\n\n"
            ."```php\n%s```",
            $table,
            $result['fields'],
            $result['class'],
            $result['class'],
            $table,
            $result['label'],
            $result['code']
        ));
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
