<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Console;

use Aimanong\Aimanong;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\AiPromptEmitter;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Aimanong\Schema\Emitters\OpenApiEmitter;
use Aimanong\Schema\Emitters\TypeScriptEmitter;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 生成 Schema 产物（ThinkPHP 版）—— `aimanong:schema`。
 *
 * 一份声明 → 四份产物：
 *   JSON Schema（前端运行时）/ TypeScript（类型）
 *   / OpenAPI（REST 文档）/ AI 提示词（LLM 与 MCP）
 *
 * ⚠️ **产物的字节内容必须与 Laravel 侧逐字一致** —— 那是「四份产物
 * 是声明的唯一真相」这个主张的检验点。若两个框架生成的 schema.json
 * 不同，声明就不是唯一真相了。所以这里只换 I/O，不动逻辑。
 */
final class SchemaCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('aimanong:schema')
            ->addOption('check', null, Option::VALUE_NONE, '仅检测漂移，不写入文件')
            ->addOption('out', null, Option::VALUE_REQUIRED, '输出目录，默认 <项目根>/aimanong')
            ->setDescription('从 Resource 声明生成 Schema 产物（JSON/TS/OpenAPI/AI 提示词）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $registry = Aimanong::registry();
        $resources = $registry->all();

        if ($resources === []) {
            $output->writeln('<comment>尚未注册任何 Resource。</comment>');

            return 0;
        }

        $compiler = new Compiler;
        $json = new JsonSchemaEmitter;
        $ts = new TypeScriptEmitter;
        $openapi = new OpenApiEmitter;
        $ai = new AiPromptEmitter;

        $allJson = [];
        $allTs = [];
        $allOpenApi = ['paths' => [], 'components' => ['schemas' => []]];
        $allAi = [];

        foreach ($resources as $uri => $class) {
            $node = $compiler->compile($class);

            $allJson[$uri] = $json->emit($node);
            $allTs[] = $ts->emit($node);

            $oa = $openapi->emit($node);
            $allOpenApi['paths'] = array_merge($allOpenApi['paths'], $oa['paths']);
            $allOpenApi['components']['schemas'] = array_merge(
                $allOpenApi['components']['schemas'],
                $oa['components']['schemas']
            );

            $allAi[] = $ai->emit($node);
        }

        $files = [
            'schema.json' => json_encode($allJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            // 基类型只输出一次，必须置顶 —— 否则接口引用的类型找不到
            'types.ts' => $ts->header()."\n\n".implode("\n\n", $allTs),
            'openapi.json' => json_encode($allOpenApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'ai-context.md' => implode("\n\n", $allAi),
        ];

        $outDir = $this->outDir($input);

        if ((bool) $input->getOption('check')) {
            return $this->checkDrift($output, $outDir, $files);
        }

        if (! is_dir($outDir) && ! mkdir($outDir, 0755, true) && ! is_dir($outDir)) {
            $output->writeln("<error>无法创建输出目录: {$outDir}</error>");

            return 1;
        }

        foreach ($files as $name => $content) {
            file_put_contents($outDir.'/'.$name, $content."\n");
            $output->writeln("<info>✓ 已生成 {$name}</info>");
        }

        $output->writeln('');
        $output->writeln("输出目录: {$outDir}");

        return 0;
    }

    /**
     * @param  array<string, string|false>  $files
     */
    protected function checkDrift(Output $output, string $outDir, array $files): int
    {
        // 与 Laravel 侧同一套逻辑：目录不存在 = 首次，不是漂移
        if (! is_dir($outDir)) {
            $output->writeln('<comment>⚠ Schema 产物目录尚不存在（首次使用）。</comment>');
            $output->writeln('  请执行: php think aimanong:schema');
            $output->writeln('');
            $output->writeln('<info>ℹ 这不是错误 —— 只是还没有生成过产物。</info>');

            return 0;
        }

        $drift = false;

        foreach ($files as $name => $content) {
            $path = $outDir.'/'.$name;

            if (! file_exists($path)) {
                $output->writeln("<error>✗ 缺失产物: {$name}</error>");
                $drift = true;

                continue;
            }

            if (file_get_contents($path) !== $content."\n") {
                $output->writeln("<error>✗ 产物已漂移: {$name}（声明已变更但未重新生成）</error>");
                $drift = true;
            } else {
                $output->writeln("<info>✓ 一致: {$name}</info>");
            }
        }

        if ($drift) {
            $output->writeln('');
            $output->writeln('<error>Schema 产物漂移检测失败。请执行: php think aimanong:schema</error>');

            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>✅ 全部产物与声明一致</info>');

        return 0;
    }

    protected function outDir(Input $input): string
    {
        $out = $input->getOption('out');

        if (is_string($out) && $out !== '') {
            return rtrim($out, '/');
        }

        return rtrim(app()->getRootPath(), '/').'/aimanong';
    }
}
