<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Aimanong;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\AiPromptEmitter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class DescribeResource extends Tool
{
    protected string $description = <<<'MARKDOWN'
        获取某个 Resource 的完整定义：列、字段、类型、可选值、校验规则。
        在修改或新增页面前调用它，避免猜错字段名。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $uri = (string) $request->string('uri');

        $class = Aimanong::registry()->find($uri);

        if ($class === null) {
            $available = array_keys(Aimanong::registry()->all());

            return Response::text(sprintf(
                "Resource [%s] 未注册。\n\n已注册: %s\n\n提示：可先用 list_resources 查看全部。",
                $uri,
                $available === [] ? '（无）' : implode(', ', $available)
            ));
        }

        $node = (new Compiler)->compile($class);

        return Response::text((new AiPromptEmitter)->emit($node));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uri' => $schema->string()
                ->description('Resource 的 uri，如 users')
                ->required(),
        ];
    }
}
