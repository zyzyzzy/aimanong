<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInput;
use Laravel\Mcp\Server\ToolResponse;

class SearchKnowledgeTool extends Tool
{
    protected string $name = 'aimind_search';
    protected string $description = '搜索 Aimind 经验库。在遇到框架问题前先查这里，可能已有解决方案。';

    public function schema(ToolInput $input): void
    {
        $input->string('query')->description('搜索关键词（空格分隔）');
        $input->array('tags')->description('标签过滤，如 ["enum","form","select"]');
        $input->string('framework_version')->description('框架版本，如 1.6.3');
        $input->number('min_confidence')->description('最低置信度 0-1，默认 0.3');
    }

    public function handle(array $arguments): ToolResponse
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->search([
            'query' => $arguments['query'] ?? null,
            'tags' => $arguments['tags'] ?? null,
            'framework_version' => $arguments['framework_version'] ?? null,
            'min_confidence' => $arguments['min_confidence'] ?? 0.3,
        ]);

        if (isset($result['error'])) {
            return ToolResponse::text("Aimind 平台不可用: {$result['error']}");
        }

        if ($result['total'] === 0) {
            return ToolResponse::text('未找到相关经验。可以考虑用 aimind_ask 发布问题，或用 aimind_share 分享你的发现。');
        }

        $lines = ["找到 {$result['total']} 条相关经验：\n"];

        foreach ($result['results'] as $exp) {
            $reliable = $exp['reliable'] ? ' 🟢可靠' : '';
            $lines[] = "• [{$exp['experience_id']}] conf={$exp['confidence']}{$reliable}";
            $lines[] = "  {$exp['summary']}";

            if ($exp['fix']) {
                $lines[] = "  修复: {$exp['fix']}";
            }
        }

        return ToolResponse::text(implode("\n", $lines));
    }
}
