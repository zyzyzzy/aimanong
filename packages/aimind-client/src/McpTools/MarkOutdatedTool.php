<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\McpTools;

use Aimanong\AimindClient\AimindClient;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInput;
use Laravel\Mcp\Server\ToolResponse;

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

    public function schema(ToolInput $input): void
    {
        $input->string('version')->required()->description('新版本号，如 1.6.4');
        $input->array('fixed_tags')->description('本次修复涉及的标签（可选，精确标记）');
        $input->string('note')->description('版本说明');
    }

    public function handle(array $arguments): ToolResponse
    {
        /** @var AimindClient $client */
        $client = app(AimindClient::class);

        $result = $client->markOutdated(array_filter([
            'version' => $arguments['version'],
            'fixed_tags' => $arguments['fixed_tags'] ?? null,
            'note' => $arguments['note'] ?? null,
        ]));

        if (isset($result['error'])) {
            return ToolResponse::text("Aimind 平台不可用: {$result['error']}");
        }

        return ToolResponse::text($result['message']);
    }
}
