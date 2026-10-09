<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Aimanong\Aimanong;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\AiPromptEmitter;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Aimanong\Schema\Emitters\OpenApiEmitter;
use Aimanong\Schema\Emitters\TypeScriptEmitter;
use Illuminate\Console\Command;

/**
 * 生成 Schema 产物。
 *
 * 一份声明 → 四份产物：
 *   JSON Schema（前端运行时）/ TypeScript（类型）
 *   / OpenAPI（REST 文档）/ AI 提示词（LLM 与 MCP）
 */
class SchemaCommand extends Command
{
    protected $signature = 'aimanong:schema
                            {--check : 仅检测漂移，不写入文件}
                            {--out= : 输出目录，默认 resource_path("aimanong")}';

    protected $description = '从 Resource 声明生成 Schema 产物（JSON/TS/OpenAPI/AI 提示词）';

    public function handle(): int
    {
        $compiler = new Compiler;
        $registry = Aimanong::registry();

        $resources = $registry->all();

        if ($resources === []) {
            $this->warn('尚未注册任何 Resource。');

            return self::SUCCESS;
        }

        $isCheck = (bool) $this->option('check');
        $outDir = $this->outDir();

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
            'types.ts' => implode("\n\n", $allTs),
            'openapi.json' => json_encode($allOpenApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'ai-context.md' => implode("\n\n", $allAi),
        ];

        if ($isCheck) {
            return $this->checkDrift($outDir, $files);
        }

        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        foreach ($files as $name => $content) {
            file_put_contents($outDir.'/'.$name, $content.("\n"));
            $this->info("✓ 已生成 {$name}");
        }

        $this->newLine();
        $this->line("输出目录: {$outDir}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string|false>  $files
     */
    protected function checkDrift(string $outDir, array $files): int
    {
        $drift = false;

        foreach ($files as $name => $content) {
            $path = $outDir.'/'.$name;

            if (! file_exists($path)) {
                $this->error("✗ 缺失产物: {$name}");
                $drift = true;

                continue;
            }

            $existing = file_get_contents($path);

            if ($existing !== $content."\n") {
                $this->error("✗ 产物已漂移: {$name}（声明已变更但未重新生成）");
                $drift = true;
            } else {
                $this->info("✓ 一致: {$name}");
            }
        }

        if ($drift) {
            $this->newLine();
            $this->error('Schema 产物漂移检测失败。请执行: php artisan aimanong:schema');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✅ 全部产物与声明一致');

        return self::SUCCESS;
    }

    protected function outDir(): string
    {
        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            return rtrim($out, '/');
        }

        return resource_path('aimanong');
    }
}
