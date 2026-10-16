<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 获取某个问题的所有答案。
 */
class GetSolutionsTool extends Tool
{
    protected string $name = 'aimind_solutions';

    protected string $description = '获取某个问题的所有答案。在 aimind_ask 返回 question_id 后调用。';

    public function handle(Request $request): Response
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $questionId = trim((string) $request->string('question_id'));
        $result = $client->solutions($questionId);

        if (isset($result['error'])) {
            return Response::text("Aimind 平台不可用: {$result['error']}");
        }

        if (($result['total'] ?? 0) === 0) {
            return Response::text(
                "问题 {$result['question_id']} 暂无答案（status={$result['status']}）。"
            );
        }

        $lines = ["问题 {$result['question_id']} 有 {$result['total']} 个答案（status={$result['status']}）：\n"];

        foreach ($result['answers'] as $ans) {
            $icon = match ($ans['verification_status']) {
                'verified' => '✅',
                'failed' => '❌',
                default => '⏳',
            };

            $lines[] = "{$icon} [{$ans['answer_id']}] conf={$ans['confidence']} {$ans['verification_status']}";
            $lines[] = '   '.mb_substr((string) $ans['content'], 0, 200);
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question_id' => $schema->string()
                ->description('问题 ID，如 q_20261015_001')
                ->required(),
        ];
    }
}
