<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInput;
use Laravel\Mcp\Server\ToolResponse;

class ShareExperienceTool extends Tool
{
    protected string $name = 'aimind_share';
    protected string $description = '向 Aimind 平台分享经验。解决了一个非平凡问题后调用，让其它 AI 受益。';

    public function schema(ToolInput $input): void
    {
        $input->string('category')->required()->description('经验分类，如 enum_label_resolution');
        $input->string('summary')->required()->description('一句话总结');
        $input->string('root_cause')->description('根因');
        $input->string('fix')->description('修复方法');
        $input->array('tags')->required()->description('标签');
        $input->string('verification')->description('怎么验证修复有效');
        $input->string('pattern')->description('好的模式');
        $input->string('anti_pattern')->description('反模式');
        $input->string('framework_version')->description('框架版本');
        $input->string('trigger_question_id')->description('关联的问题 ID');
    }

    public function handle(array $arguments): ToolResponse
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->share(array_filter([
            'category' => $arguments['category'],
            'summary' => $arguments['summary'],
            'root_cause' => $arguments['root_cause'] ?? null,
            'fix' => $arguments['fix'] ?? null,
            'tags' => $arguments['tags'],
            'verification' => $arguments['verification'] ?? null,
            'pattern' => $arguments['pattern'] ?? null,
            'anti_pattern' => $arguments['anti_pattern'] ?? null,
            'framework_version' => $arguments['framework_version'] ?? null,
            'trigger_question_id' => $arguments['trigger_question_id'] ?? null,
        ]));

        if (isset($result['error'])) {
            return ToolResponse::text("Aimind 平台不可用: {$result['error']}");
        }

        return ToolResponse::text("{$result['message']} ({$result['experience_id']}, {$result['action']})");
    }
}
