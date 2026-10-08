<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Ai\Verifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ValidateDeclaration extends Tool
{
    protected string $description = <<<'MARKDOWN'
        校验一个 Resource 声明是否合法。

        生成或修改 Resource 后必须调用：它会检查
        类是否存在、是否继承 Resource、uri 合法性、model 是否存在、
        以及能否成功编译。

        返回 valid=true 表示可直接使用；false 时会给出 did_you_mean 与修正示例。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $class = (string) $request->string('resource');

        $result = (new Verifier())->verify($class);

        if ($result['valid']) {
            return Response::text(sprintf(
                "✅ %s 校验通过。\n\n无需修改，可直接使用。",
                $class
            ));
        }

        $lines = [sprintf('❌ %s 校验未通过（%d 个问题）', $class, $result['issue_count']), ''];

        foreach ($result['issues'] as $i => $issue) {
            $lines[] = sprintf('%d. [%s] %s', $i + 1, $issue['code'], $issue['message']);

            if (! empty($issue['did_you_mean'])) {
                $lines[] = sprintf('   是否想用: %s', $issue['did_you_mean']);
            }
            if (! empty($issue['hint'])) {
                $lines[] = sprintf('   提示: %s', $issue['hint']);
            }
            if (! empty($issue['example'])) {
                $lines[] = sprintf('   示例: %s', $issue['example']);
            }

            $lines[] = '';
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()
                ->description('Resource 类全限定名，如 App\\Aimanong\\UserResource')
                ->required(),
        ];
    }
}
