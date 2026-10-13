<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Console;

use Aimanong\Ai\Verifier;
use Aimanong\Aimanong;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * 项目自检命令（ThinkPHP 版）—— `ai:verify`。
 *
 * AI 生成代码后的第一道防线：不依赖人肉 review。
 * 支持 `--json`，便于 AI 与 CI 解析。
 *
 * 与 Laravel 侧**同一套输出契约**（同名的 JSON 字段、同样的退出码），
 * 这样 CI / AI 的工具链不用按框架分叉。
 */
final class VerifyCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('ai:verify')
            ->addArgument('resource', Argument::OPTIONAL, '只校验指定 Resource 类名')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出（AI/CI 友好）')
            ->setDescription('校验 Resource 声明合法性（AI 自检入口）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $verifier = new Verifier;
        $isJson = (bool) $input->getOption('json');
        $target = $input->getArgument('resource');

        if (is_string($target) && $target !== '') {
            return $this->reportOne($output, $verifier->verify($target), $isJson);
        }

        $registry = Aimanong::registry();
        $failures = $registry->failures();
        $all = $registry->all();

        // 登记失败的 Resource 必须报告，否则用户不知道注册表里
        // 有一行指向了已删除的类
        if ($failures !== []) {
            foreach ($failures as $f) {
                $output->writeln("<error>✗ 注册失败: {$f}</error>");
            }

            $output->writeln('    → 这些类不存在或未继承 Aimanong\Resource，请检查服务注册');
            $output->writeln('');
        }

        if ($all === []) {
            if ($isJson) {
                $output->writeln((string) json_encode([
                    'valid' => true, 'resources' => [], 'message' => '尚未注册任何 Resource',
                ], JSON_UNESCAPED_UNICODE));
            } else {
                $output->writeln('<comment>尚未注册任何 Resource。</comment>');
            }

            return 0;
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
            $output->writeln((string) json_encode([
                'valid' => $failed === 0,
                'total' => count($all),
                'failed' => $failed,
                'resources' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $failed === 0 ? 0 : 1;
        }

        foreach ($results as $uri => $r) {
            if ($r['valid']) {
                $output->writeln("<info>✓ {$uri}</info>");

                continue;
            }

            $output->writeln("<error>✗ {$uri}（{$r['issue_count']} 个问题）</error>");

            foreach ($r['issues'] as $issue) {
                $output->writeln("    [{$issue['code']}] {$issue['message']}");

                if (! empty($issue['did_you_mean'])) {
                    $output->writeln("    → 是否想用: {$issue['did_you_mean']}");
                }
            }
        }

        $output->writeln('');

        if ($failed === 0) {
            $output->writeln(sprintf('<info>✅ 全部通过（%d 个 Resource）</info>', count($all)));

            return 0;
        }

        $output->writeln(sprintf('<error>❌ %d / %d 未通过</error>', $failed, count($all)));

        return 1;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function reportOne(Output $output, array $result, bool $isJson): int
    {
        if ($isJson) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['valid'] ? 0 : 1;
        }

        if ($result['valid']) {
            $output->writeln('<info>✅ 校验通过</info>');

            return 0;
        }

        $output->writeln(sprintf('<error>❌ 未通过（%d 个问题）</error>', $result['issue_count']));

        foreach ($result['issues'] as $issue) {
            $output->writeln("  [{$issue['code']}] {$issue['message']}");

            foreach (['did_you_mean' => '是否想用', 'hint' => '提示', 'example' => '示例'] as $key => $label) {
                if (! empty($issue[$key])) {
                    $output->writeln("  → {$label}: {$issue[$key]}");
                }
            }
        }

        return 1;
    }
}
