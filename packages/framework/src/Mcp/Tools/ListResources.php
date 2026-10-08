<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Ai\Capabilities;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListResources extends Tool
{
    protected string $description = <<<'MARKDOWN'
        列出项目中所有已注册的 Resource 及其字段、可搜索/可排序列、校验规则。
        这是了解一个 Aimanong 项目的第一步 —— 先调用它，再决定如何写代码。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $capabilities = new Capabilities();
        $resources = $capabilities->resources();

        if ($resources === []) {
            return Response::text("项目中尚未注册任何 Resource。\n\n提示：Resource 通常放在 app/Aimanong/ 目录，命名 {Name}Resource，并在 ServiceProvider 中调用 Aimanong::registry()->register()。");
        }

        $lines = ['# 已注册 Resource', ''];

        foreach ($resources as $r) {
            $lines[] = "## {$r['label']}（uri: {$r['uri']}）";
            $lines[] = "- 类: `{$r['resource_class']}`";
            $lines[] = "- 模型: `{$r['model']}`";

            $searchable = $r['grid']['searchable'];
            $sortable = $r['grid']['sortable'];
            if ($searchable !== []) {
                $lines[] = '- 可搜索列: '.implode(', ', $searchable);
            }
            if ($sortable !== []) {
                $lines[] = '- 可排序列: '.implode(', ', $sortable);
            }

            $fields = array_map(
                fn (array $f): string => $f['name'].'('.$f['type'].')',
                $r['form']['fields']
            );
            if ($fields !== []) {
                $lines[] = '- 表单字段: '.implode(', ', $fields);
            }

            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '下一步：用 describe_resource 查看某个 Resource 的完整定义，';
        $lines[] = '或用 scaffold_resource 从数据表生成新 Resource。';

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
