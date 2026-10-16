<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 报告框架 bug。
 */
class ReportBugTool extends Tool
{
    protected string $name = 'aimind_bug';

    protected string $description = '向 Aimind 平台报告框架 bug。当确认是框架自身的问题（不是用法错误）时调用。';

    public function handle(Request $request): Response
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->reportBug(array_filter([
            'title' => trim((string) $request->string('title')),
            'description' => trim((string) $request->string('description')),
            'expected' => trim((string) $request->string('expected')),
            'actual' => trim((string) $request->string('actual')),
            'code' => trim((string) $request->string('code')) ?: null,
            'framework_version' => trim((string) $request->string('framework_version')) ?: null,
            'tags' => $request->array('tags') ?: null,
            'source_session' => trim((string) $request->string('source_session')) ?: null,
        ]));

        if (isset($result['error'])) {
            return Response::text("Aimind 平台不可用: {$result['error']}");
        }

        $msg = "{$result['message']} ({$result['experience_id']})";

        if (! empty($result['aggregated_suggestion'])) {
            $msg .= "\n\n🎯 {$result['aggregated_suggestion']['message']}";
        }

        return Response::text($msg);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Bug 标题')->required(),
            'description' => $schema->string()->description('详细描述')->required(),
            'expected' => $schema->string()->description('期望行为')->required(),
            'actual' => $schema->string()->description('实际行为')->required(),
            'code' => $schema->string()->description('触发 bug 的代码'),
            'framework_version' => $schema->string()->description('框架版本'),
            'tags' => $schema->array()->description('标签'),
            'source_session' => $schema->string()->description('你的会话 ID'),
        ];
    }
}
