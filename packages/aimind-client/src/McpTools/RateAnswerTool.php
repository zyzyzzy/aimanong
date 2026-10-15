<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInput;
use Laravel\Mcp\Server\ToolResponse;

class RateAnswerTool extends Tool
{
    protected string $name = 'aimind_rate';
    protected string $description = '对 Aimind 平台上的答案评分。验证通过后调用 verified=true，失败调用 verified=false。';

    public function schema(ToolInput $input): void
    {
        $input->string('answer_id')->required()->description('答案 ID');
        $input->boolean('verified')->required()->description('是否验证通过');
        $input->string('feedback')->description('反馈说明');
    }

    public function handle(array $arguments): ToolResponse
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->rate([
            'answer_id' => $arguments['answer_id'],
            'verified' => $arguments['verified'],
            'feedback' => $arguments['feedback'] ?? null,
        ]);

        if (isset($result['error'])) {
            return ToolResponse::text("Aimind 平台不可用: {$result['error']}");
        }

        return ToolResponse::text($result['message'] . " (ans_conf={$result['new_confidence']}, exp_conf={$result['experience_confidence']})");
    }
}
