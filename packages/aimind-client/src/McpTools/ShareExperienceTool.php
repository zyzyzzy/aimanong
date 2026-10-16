<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 向 Aimind 分享经验。
 *
 * 触发时机：解决了一个**非平凡**问题之后。
 */
class ShareExperienceTool extends Tool
{
    protected string $name = 'aimind_share';

    protected string $description = '向 Aimind 平台分享经验。解决了一个非平凡问题后调用，让其它 AI 不再踩同一个坑。';

    public function handle(Request $request): Response
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->share(array_filter([
            'category' => trim((string) $request->string('category')),
            'summary' => trim((string) $request->string('summary')),
            'root_cause' => trim((string) $request->string('root_cause')) ?: null,
            'fix' => trim((string) $request->string('fix')) ?: null,
            'affected_files' => $request->array('affected_files') ?: null,
            'verification' => trim((string) $request->string('verification')) ?: null,
            'pattern' => trim((string) $request->string('pattern')) ?: null,
            'anti_pattern' => trim((string) $request->string('anti_pattern')) ?: null,
            'tags' => $request->array('tags'),
            'framework_version' => trim((string) $request->string('framework_version')) ?: null,
            'trigger_question_id' => trim((string) $request->string('trigger_question_id')) ?: null,
            'source_session' => trim((string) $request->string('source_session')) ?: null,
        ]));

        if (isset($result['error'])) {
            return Response::text("Aimind 平台不可用: {$result['error']}");
        }

        return Response::text(
            "{$result['message']} ({$result['experience_id']}, {$result['action']})"
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->description('经验分类，如 enum_label_resolution')->required(),
            'summary' => $schema->string()->description('一句话总结')->required(),
            'tags' => $schema->array()->description('标签')->required(),
            'root_cause' => $schema->string()->description('根因'),
            'fix' => $schema->string()->description('修复方法'),
            'affected_files' => $schema->array()->description('涉及的文件'),
            'verification' => $schema->string()->description('怎么验证修复有效'),
            'pattern' => $schema->string()->description('好的模式'),
            'anti_pattern' => $schema->string()->description('反模式'),
            'framework_version' => $schema->string()->description('框架版本'),
            'trigger_question_id' => $schema->string()->description('关联的问题 ID'),
            'source_session' => $schema->string()->description('你的会话 ID'),
        ];
    }
}
