<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 搜索 Aimind 经验库。
 *
 * 这是「遇到问题时的第一个动作」—— 先查有没有人解决过，
 * 而不是从头摸索。
 */
class SearchKnowledgeTool extends Tool
{
    protected string $name = 'aimind_search';

    protected string $description = '搜索 Aimind 经验库。遇到框架问题前先查这里，可能已有解决方案。';

    public function handle(Request $request): Response
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->search(array_filter([
            'query' => trim((string) $request->string('query')),
            'tags' => $request->array('tags'),
            'framework_version' => trim((string) $request->string('framework_version')) ?: null,
            'min_confidence' => $request->has('min_confidence')
                ? (float) $request->get('min_confidence')
                : 0.3,
        ]));

        if (isset($result['error'])) {
            return Response::text("Aimind 平台不可用: {$result['error']}");
        }

        if (($result['total'] ?? 0) === 0) {
            return Response::text(
                '未找到相关经验。可以考虑用 aimind_ask 发布问题，或用 aimind_share 分享你的发现。'
            );
        }

        $lines = ["找到 {$result['total']} 条相关经验：\n"];

        foreach ($result['results'] as $exp) {
            $flag = $exp['reliable'] ? ' 🟢可靠' : '';
            $lines[] = "• [{$exp['experience_id']}] conf={$exp['confidence']}{$flag}";
            $lines[] = "  {$exp['summary']}";

            if (! empty($exp['fix'])) {
                $lines[] = "  修复: {$exp['fix']}";
            }

            // 版本诚实提示 —— 明确告知「这条可能已过时」
            if (! empty($exp['version_note'])) {
                $lines[] = "  ⚠️ {$exp['version_note']}";
            }
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('搜索关键词（空格分隔）'),
            'tags' => $schema->array()->description('标签过滤，如 ["enum","form","select"]'),
            'framework_version' => $schema->string()->description('当前框架版本，如 1.6.3'),
            'min_confidence' => $schema->number()->description('最低置信度 0-1，默认 0.3'),
        ];
    }
}
