<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Ai\Verifier;
use Aimanong\Aimanong;
use Aimanong\Schema\Compiler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 项目级自检：校验所有 Resource。
 */
class RunVerify extends Tool
{
    protected string $description = <<<'MARKDOWN'
        对项目内所有已注册 Resource 做一次完整自检。

        改动多个 Resource 后调用，确认整体没有破坏。
        相当于命令行的 php artisan ai:verify。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $all = Aimanong::registry()->all();

        if ($all === []) {
            return Response::text('项目中尚未注册任何 Resource，无需校验。');
        }

        $verifier = new Verifier;
        $compiler = new Compiler;

        $ok = 0;
        $failed = [];
        $lines = ['# 项目自检', ''];

        foreach ($all as $uri => $class) {
            $result = $verifier->verify($class);

            if ($result['valid']) {
                // 编译产物一致性
                try {
                    $node = $compiler->compile($class);
                    $lines[] = sprintf(
                        '✅ %s（%s）— %d 列 / %d 字段',
                        $uri,
                        $class,
                        count($node->columns),
                        count($node->fields)
                    );
                    $ok++;
                } catch (\Throwable $e) {
                    $lines[] = sprintf('❌ %s — 编译失败: %s', $uri, $e->getMessage());
                    $failed[] = $uri;
                }
            } else {
                $lines[] = sprintf('❌ %s — %d 个问题', $uri, $result['issue_count']);
                foreach ($result['issues'] as $issue) {
                    $lines[] = sprintf('   [%s] %s', $issue['code'], $issue['message']);
                }
                $failed[] = $uri;
            }
        }

        $lines[] = '';
        $lines[] = sprintf('通过 %d / %d，失败 %d', $ok, count($all), count($failed));

        if ($failed !== []) {
            $lines[] = '失败的 Resource: '.implode(', ', $failed);
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
