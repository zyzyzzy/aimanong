<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInput;
use Laravel\Mcp\Server\ToolResponse;

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

    public function schema(ToolInput $input): void
    {
        $input->string('status')->description('过滤状态: pending_review / accepted / implemented / rejected');
        $input->string('priority')->description('过滤优先级: low / medium / high / critical');
        $input->number('limit')->description('返回条数，默认 20');
    }

    public function handle(array $arguments): ToolResponse
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->suggestions(array_filter([
            'status' => $arguments['status'] ?? null,
            'priority' => $arguments['priority'] ?? null,
            'limit' => $arguments['limit'] ?? 20,
        ]));

        if (isset($result['error'])) {
            return ToolResponse::text("Aimind 平台不可用: {$result['error']}");
        }

        if (($result['total'] ?? 0) === 0) {
            return ToolResponse::text('暂无改进建议。同一类问题被 3 个以上独立会话报告后会自动生成。');
        }

        $lines = ["共 {$result['total']} 条框架改进建议：\n"];

        foreach ($result['suggestions'] as $s) {
            $lines[] = "【{$s['priority']}】{$s['suggestion_id']} ({$s['status']})";
            $lines[] = "  标签: " . implode(', ', $s['tags']);
            $lines[] = "  独立会话 {$s['independent_sessions']} 个 / 共出现 {$s['occurrences']} 次";
            $lines[] = "  建议: " . mb_substr($s['suggested_fix'], 0, 150);
            $lines[] = '';
        }

        return ToolResponse::text(implode("\n", $lines));
    }
}
