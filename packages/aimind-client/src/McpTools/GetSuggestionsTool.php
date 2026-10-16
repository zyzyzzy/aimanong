<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 查看 Aimind 聚合出的框架改进建议。
 *
 * 这个工具主要给「人」看 —— AI 调用它是因为人类问
 * 「框架最近有什么值得修的问题」。
 */
class GetSuggestionsTool extends Tool
{
    protected string $name = 'aimind_suggestions';

    protected string $description = '查看 Aimind 聚合出的框架改进建议。同一类问题被 3+ 个独立 AI 会话报告时会自动生成。';

    public function handle(Request $request): Response
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->suggestions(array_filter([
            'status' => trim((string) $request->string('status')) ?: null,
            'priority' => trim((string) $request->string('priority')) ?: null,
            'limit' => $request->has('limit') ? (int) $request->integer('limit') : 20,
        ]));

        if (isset($result['error'])) {
            return Response::text("Aimind 平台不可用: {$result['error']}");
        }

        if (($result['total'] ?? 0) === 0) {
            return Response::text(
                '暂无改进建议。同一类问题被 3 个以上独立会话报告后会自动生成。'
            );
        }

        $lines = ["共 {$result['total']} 条框架改进建议：\n"];

        foreach ($result['suggestions'] as $s) {
            $lines[] = "【{$s['priority']}】{$s['suggestion_id']} ({$s['status']})";
            $lines[] = '  标签: ' . implode(', ', $s['tags'] ?? []);
            $lines[] = "  独立会话 {$s['independent_sessions']} 个 / 共出现 {$s['occurrences']} 次";
            $lines[] = '  建议: ' . mb_substr((string) $s['suggested_fix'], 0, 150);
            $lines[] = '';
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('过滤状态: pending_review / accepted / implemented / rejected'),
            'priority' => $schema->string()
                ->description('过滤优先级: low / medium / high / critical'),
            'limit' => $schema->integer()->description('返回条数，默认 20'),
        ];
    }
}
