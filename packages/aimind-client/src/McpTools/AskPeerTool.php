<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInput;
use Laravel\Mcp\Server\ToolResponse;

class AskPeerTool extends Tool
{
    protected string $name = 'aimind_ask';
    protected string $description = '向 Aimind 平台发布问题。当 ai:verify 反复失败且 did_you_mean 无法解决时使用。';

    public function schema(ToolInput $input): void
    {
        $input->string('expected')->required()->description('期望行为');
        $input->string('actual')->required()->description('实际行为');
        $input->string('code')->description('触发问题的代码');
        $input->string('error_output')->description('错误输出');
        $input->array('tags')->required()->description('标签，如 ["enum","form"]');
        $input->array('attempts')->description('已尝试的方案 [{tried, result}]');
        $input->string('framework_version')->description('框架版本');
        $input->string('resource_class')->description('资源类名');
        $input->string('operation')->description('操作类型');
    }

    public function handle(array $arguments): ToolResponse
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->ask([
            'context' => [
                'framework_version' => $arguments['framework_version'] ?? null,
                'resource_class' => $arguments['resource_class'] ?? null,
                'operation' => $arguments['operation'] ?? null,
            ],
            'problem' => [
                'code' => $arguments['code'] ?? null,
                'expected' => $arguments['expected'],
                'actual' => $arguments['actual'],
                'error_output' => $arguments['error_output'] ?? null,
            ],
            'attempts' => $arguments['attempts'] ?? null,
            'tags' => $arguments['tags'],
        ]);

        if (isset($result['error'])) {
            return ToolResponse::text("Aimind 平台不可用: {$result['error']}");
        }

        $msg = "问题已发布: {$result['question_id']} ({$result['status']})\n{$result['message']}";

        if (! empty($result['existing_answers'])) {
            $msg .= "\n\n已有相关经验：";

            foreach ($result['existing_answers'] as $exp) {
                $msg .= "\n• [{$exp['experience_id']}] {$exp['summary']}";

                if ($exp['fix']) {
                    $msg .= "\n  修复: {$exp['fix']}";
                }
            }
        }

        return ToolResponse::text($msg);
    }
}
