<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 发版时通知 Aimind。
 *
 * 框架发新版后，旧经验可能已失效。调用这个工具把它们标记为
 * 「待重新验证」——搜索时就会带上提醒，而不是让 AI 盲信旧方案。
 */
class MarkOutdatedTool extends Tool
{
    protected string $name = 'aimind_outdated';

    protected string $description = '通知 Aimind 框架已发新版，把旧经验标记为待重新验证。发版后调用一次。';

    public function handle(Request $request): Response
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->markOutdated(array_filter([
            'version' => trim((string) $request->string('version')),
            'fixed_tags' => $request->array('fixed_tags') ?: null,
            'note' => trim((string) $request->string('note')) ?: null,
        ]));

        if (isset($result['error'])) {
            return Response::text("Aimind 平台不可用: {$result['error']}");
        }

        return Response::text($result['message']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'version' => $schema->string()->description('新版本号，如 1.6.4')->required(),
            'fixed_tags' => $schema->array()->description('本次修复涉及的标签（可选，精确标记）'),
            'note' => $schema->string()->description('版本说明'),
        ];
    }
}
