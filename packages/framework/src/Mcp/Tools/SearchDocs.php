<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Support\FieldType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 文档检索（本地知识，无需联网）。
 */
class SearchDocs extends Tool
{
    protected string $description = <<<'MARKDOWN'
        在框架内置文档中检索用法（字段类型、列选项、表单选项、校验规则、铁律）。

        本地检索，无需联网。不确定某个 API 怎么用就调它。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $query = strtolower((string) $request->string('query'));

        $sections = [
            '铁律' => [
                '方法签名唯一，不存在重载，不要猜第二种写法',
                '不嵌套闭包，所有配置走链式调用',
                '字段必须是数据库真实字段或已定义访问器',
                '状态类字段用 PHP Enum + ->options(Enum::cases())',
                '生成后必须调用 validate_declaration',
                '不要写前端代码 —— 前端由 Schema 自动渲染',
            ],
            '字段类型' => array_map(
                fn (string $t): string => sprintf('%s → JSON 类型 %s', $t, FieldType::toJsonType($t)),
                FieldType::all()
            ),
            '列选项' => [
                'sortable() 允许排序',
                'searchable() 加入快捷搜索',
                'filter() 加入筛选器',
                'dateTime() 格式化为日期时间',
                'using(EnumClass::class) 枚举值→标签',
                'map(array) 值映射',
                'width(int) 列宽',
                'label(string) 列标题',
            ],
            '表单选项' => [
                'label(string) 字段标签',
                'required() 必填，自动生成 required 规则',
                'default(mixed) 默认值',
                'readonly() / hidden() 只读 / 隐藏',
                'rules(string|array) 追加验证规则',
                'max(int) / min(int) 长度限制',
                'options(array|Enum::cases()) 下拉选项',
                'rows(int) 文本域行数（textarea）',
                'placeholder(string) / help(string) 占位与帮助文本',
            ],
            '工作流' => [
                '1. list_resources 了解项目',
                '2. describe_resource 看字段',
                '3. scaffold_resource 从表生成（推荐）',
                '4. validate_declaration 校验',
                '5. run_verify 整体自检',
            ],
        ];

        if ($query === '') {
            $out = ['# Aimanong 文档', ''];
            foreach ($sections as $title => $items) {
                $out[] = "## {$title}";
                foreach ($items as $item) {
                    $out[] = "- {$item}";
                }
                $out[] = '';
            }

            return Response::text(implode("\n", $out));
        }

        $out = ["# 检索: {$query}", ''];
        $hit = 0;

        foreach ($sections as $title => $items) {
            $matched = array_values(array_filter(
                $items,
                fn (string $i): bool => str_contains(strtolower($i), $query)
                    || str_contains(strtolower($title), $query)
            ));

            if ($matched === []) {
                continue;
            }

            $out[] = "## {$title}";
            foreach ($matched as $m) {
                $out[] = "- {$m}";
                $hit++;
            }
            $out[] = '';
        }

        if ($hit === 0) {
            return Response::text(sprintf(
                "未找到与 '%s' 相关的内容。\n\n可检索的主题: %s",
                $query,
                implode(' / ', array_keys($sections))
            ));
        }

        return Response::text(implode("\n", $out));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('检索关键词，留空则返回全部文档'),
        ];
    }
}
