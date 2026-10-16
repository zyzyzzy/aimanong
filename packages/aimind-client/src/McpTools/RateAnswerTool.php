<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 对 Aimind 上的答案评分。
 *
 * 这是平台自我纠错的关键一环：验证通过 confidence +0.1，失败 -0.2。
 */
class RateAnswerTool extends Tool
{
    protected string $name = 'aimind_rate';

    protected string $description = '对 Aimind 平台上的答案评分。验证通过后调用 verified=true，失败调用 verified=false。';

    public function handle(Request $request): Response
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->rate(array_filter([
            'answer_id' => trim((string) $request->string('answer_id')),
            'verified' => (bool) $request->boolean('verified'),
            'feedback' => trim((string) $request->string('feedback')) ?: null,
            'framework_version' => trim((string) $request->string('framework_version')) ?: null,
            'source_session' => trim((string) $request->string('source_session')) ?: null,
        ]));

        if (isset($result['error'])) {
            return Response::text("Aimind 平台不可用: {$result['error']}");
        }

        $expConf = $result['experience_confidence'] ?? '—';

        return Response::text(
            "{$result['message']} (ans_conf={$result['new_confidence']}, exp_conf={$expConf})"
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'answer_id' => $schema->string()->description('答案 ID')->required(),
            'verified' => $schema->boolean()->description('是否验证通过')->required(),
            'feedback' => $schema->string()->description('反馈说明'),
            'framework_version' => $schema->string()->description('验证时的框架版本'),
            'source_session' => $schema->string()->description('你的会话 ID'),
        ];
    }
}
