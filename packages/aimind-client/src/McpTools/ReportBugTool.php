<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInput;
use Laravel\Mcp\Server\ToolResponse;

class ReportBugTool extends Tool
{
    protected string $name = 'aimind_bug';
    protected string $description = '向 Aimind 平台报告框架 bug。当确认是框架自身的问题（不是用法错误）时调用。';

    public function schema(ToolInput $input): void
    {
        $input->string('title')->required()->description('Bug 标题');
        $input->string('description')->required()->description('详细描述');
        $input->string('expected')->required()->description('期望行为');
        $input->string('actual')->required()->description('实际行为');
        $input->string('code')->description('触发 bug 的代码');
        $input->string('framework_version')->description('框架版本');
        $input->array('tags')->description('标签');
    }

    public function handle(array $arguments): ToolResponse
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->reportBug([
            'title' => $arguments['title'],
            'description' => $arguments['description'],
            'expected' => $arguments['expected'],
            'actual' => $arguments['actual'],
            'code' => $arguments['code'] ?? null,
            'framework_version' => $arguments['framework_version'] ?? null,
            'tags' => $arguments['tags'] ?? null,
        ]);

        if (isset($result['error'])) {
            return ToolResponse::text("Aimind 平台不可用: {$result['error']}");
        }

        return ToolResponse::text($result['message'] . " ({$result['experience_id']})");
    }
}
