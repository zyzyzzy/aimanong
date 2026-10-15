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
            // 基类型只输出一次，必须置顶 —— 否则接口引用的类型找不到
            'types.ts' => $ts->header()."\n\n".implode("\n\n", $allTs),
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
        /*
         * ⚠️ 区分「从未生成过」与「生成过但漂移了」。
         *
         * 原先两者都报 ✗ 并返回 FAILURE。但一个新项目本来就没有
         * resources/aimanong/ 目录 —— 那不是漂移，是「还没生成过」。
         *
         * 实测（L1/L2 复测）多个 agent 报告「基线就红」，
         * 因为 setup 脚本不默认生成产物，而 CI 又跑 --check。
         * 这组合让每个新项目第一次跑 CI 必红，是个容易踩的坑。
         *
         * 现在：
         *   · 输出目录不存在 → 视为首次，提示生成，返回 SUCCESS
         *   · 目录存在但某文件缺失 → 真漂移，FAILURE
         *   · 文件存在但内容不一致 → 真漂移，FAILURE
         */
        if (! is_dir($outDir)) {
            $this->warn('⚠ Schema 产物目录尚不存在（首次使用）。');
            $this->line('  请执行: php artisan aimanong:schema');
            $this->newLine();
            $this->info('ℹ 这不是错误 —— 只是还没有生成过产物。');

            return self::SUCCESS;
        }

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
