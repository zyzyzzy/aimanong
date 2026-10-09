<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Aimanong\Ai\Verifier;
use Aimanong\Aimanong;
use Illuminate\Console\Command;

/**
 * 项目自检命令。
 *
 * AI 生成代码后的第一道防线：不依赖人肉 review。
 * 支持 --json 输出，便于 AI 与 CI 解析。
 */
class VerifyCommand extends Command
{
    protected $signature = 'ai:verify
                            {resource? : 只校验指定 Resource 类名}
                            {--json : 以 JSON 输出（AI/CI 友好）}';

    protected $description = '校验 Resource 声明合法性（AI 自检入口）';

    public function handle(): int
    {
        $verifier = new Verifier;
        $isJson = (bool) $this->option('json');

        $target = $this->argument('resource');

        if (is_string($target) && $target !== '') {
            $result = $verifier->verify($target);

            if ($isJson) {
                $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return $result['valid'] ? self::SUCCESS : self::FAILURE;
            }

            return $this->reportOne($result);
        }

        // 登记失败的 Resource 必须报告出来，否则用户不知道
        // ServiceProvider 里有一行指向已删除的类
        $failures = Aimanong::registry()->failures();

        $all = Aimanong::registry()->all();

        if ($failures !== []) {
            foreach ($failures as $f) {
                $this->error("✗ 注册失败: {$f}");
            }

            $this->line('    → 这些类不存在或未继承 Aimanong\Resource，请检查 ServiceProvider');
            $this->newLine();
        }

        if ($all === []) {
            if ($isJson) {
                $this->line((string) json_encode(['valid' => true, 'resources' => [], 'message' => '尚未注册任何 Resource']));
            } else {
                $this->warn('尚未注册任何 Resource。');
            }

            return self::SUCCESS;
        }

        $results = [];
        $failed = 0;

        foreach ($all as $uri => $class) {
            $r = $verifier->verify($class);
            $results[$uri] = $r;

            if (! $r['valid']) {
                $failed++;
            }
        }

        if ($isJson) {
            $this->line((string) json_encode([
                'valid' => $failed === 0,
                'total' => count($all),
                'failed' => $failed,
                'resources' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($results as $uri => $r) {
            if ($r['valid']) {
                $this->info("✓ {$uri}");
            } else {
                $this->error("✗ {$uri}（{$r['issue_count']} 个问题）");
                foreach ($r['issues'] as $issue) {
                    $this->line("    [{$issue['code']}] {$issue['message']}");
                    if (! empty($issue['did_you_mean'])) {
                        $this->line("    → 是否想用: {$issue['did_you_mean']}");
                    }
                }
            }
        }

        $this->newLine();

        if ($failed === 0) {
            $this->info(sprintf('✅ 全部通过（%d 个 Resource）', count($all)));

            return self::SUCCESS;
        }

        $this->error(sprintf('❌ %d / %d 未通过', $failed, count($all)));

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function reportOne(array $result): int
    {
        if ($result['valid']) {
            $this->info('✅ 校验通过');

            return self::SUCCESS;
        }

        $this->error(sprintf('❌ 未通过（%d 个问题）', $result['issue_count']));

        foreach ($result['issues'] as $issue) {
            $this->line("  [{$issue['code']}] {$issue['message']}");

            if (! empty($issue['did_you_mean'])) {
                $this->line("  → 是否想用: {$issue['did_you_mean']}");
            }
            if (! empty($issue['hint'])) {
                $this->line("  → 提示: {$issue['hint']}");
            }
            if (! empty($issue['example'])) {
                $this->line("  → 示例: {$issue['example']}");
            }
        }

        return self::FAILURE;
    }
}
