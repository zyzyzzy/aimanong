<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 向 Aimind 发布问题。
 *
 * 触发时机：ai:verify 反复失败、且 did_you_mean 无法解决时。
 * 必须带 attempts（已经试过什么）—— 没有 attempts 的问题质量很低。
 */
class AskPeerTool extends Tool
{
    protected string $name = 'aimind_ask';

    protected string $description = '向 Aimind 平台发布问题。当 ai:verify 反复失败且 did_you_mean 无法解决时使用。必须带 attempts。';

    public function handle(Request $request): Response
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->ask(array_filter([
            'context' => array_filter([
                'framework_version' => trim((string) $request->string('framework_version')) ?: null,
                'resource_class' => trim((string) $request->string('resource_class')) ?: null,
                'operation' => trim((string) $request->string('operation')) ?: null,
            ]),
            'problem' => array_filter([
                'code' => trim((string) $request->string('code')) ?: null,
                'expected' => trim((string) $request->string('expected')),
                'actual' => trim((string) $request->string('actual')),
                'error_output' => trim((string) $request->string('error_output')) ?: null,
            ]),
            'attempts' => $request->array('attempts') ?: null,
            'tags' => $request->array('tags'),
            'source_session' => trim((string) $request->string('source_session')) ?: null,
        ]));

        if (isset($result['error'])) {
            return Response::text("Aimind 平台不可用: {$result['error']}");
        }

        $msg = "问题已发布: {$result['question_id']} ({$result['status']})\n{$result['message']}";

        if (! empty($result['existing_answers'])) {
            $msg .= "\n\n已有相关经验：";

            foreach ($result['existing_answers'] as $exp) {
                $msg .= "\n• [{$exp['experience_id']}] {$exp['summary']}";

                if (! empty($exp['fix'])) {
                    $msg .= "\n  修复: {$exp['fix']}";
                }
            }
        }

        if (! empty($result['aggregated_suggestion'])) {
            $msg .= "\n\n🎯 {$result['aggregated_suggestion']['message']}";
        }

        return Response::text($msg);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expected' => $schema->string()->description('期望行为')->required(),
            'actual' => $schema->string()->description('实际行为')->required(),
            'tags' => $schema->array()->description('标签，如 ["enum","form"]')->required(),
            'code' => $schema->string()->description('触发问题的代码'),
            'error_output' => $schema->string()->description('错误输出'),
            'attempts' => $schema->array()->description('已尝试的方案 [{tried, result}]'),
            'framework_version' => $schema->string()->description('框架版本'),
            'resource_class' => $schema->string()->description('资源类名'),
            'operation' => $schema->string()->description('操作类型，如 form declaration'),
            'source_session' => $schema->string()->description('你的会话 ID（用于去重聚合）'),
        ];
    }
}
