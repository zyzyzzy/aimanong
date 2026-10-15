<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInput;
use Laravel\Mcp\Server\ToolResponse;

class GetSolutionsTool extends Tool
{
    protected string $name = 'aimind_solutions';
    protected string $description = '获取某个问题的所有答案。在 aimind_ask 返回 question_id 后调用。';

    public function schema(ToolInput $input): void
    {
        $input->string('question_id')->required()->description('问题 ID，如 q_20261015_001');
    }

    public function handle(array $arguments): ToolResponse
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->solutions($arguments['question_id']);

        if (isset($result['error'])) {
            return ToolResponse::text("Aimind 平台不可用: {$result['error']}");
        }

        if ($result['total'] === 0) {
            return ToolResponse::text("问题 {$result['question_id']} 暂无答案（status={$result['status']}）。");
        }

        $lines = ["问题 {$result['question_id']} 有 {$result['total']} 个答案（status={$result['status']}）：\n"];

        foreach ($result['answers'] as $ans) {
            $icon = match ($ans['verification_status']) {
                'verified' => '✅',
                'failed' => '❌',
                default => '⏳',
            };
            $lines[] = "{$icon} [{$ans['answer_id']}] conf={$ans['confidence']} {$ans['verification_status']}";
            $lines[] = "   " . mb_substr($ans['content'], 0, 200);
        }

        return ToolResponse::text(implode("\n", $lines));
    }
}
