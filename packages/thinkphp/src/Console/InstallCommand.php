<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Console;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 安装命令（ThinkPHP 版）—— `aimanong:install`。
 *
 * ## 与 Laravel 侧的关键差异：**它只做脚手架**
 *
 * Laravel 侧的 install 还会建表、创建管理员、同步权限节点。
 * ThinkPHP 侧**做不到**，因为认证与 RBAC 层还没实现。
 *
 * 这里没有「假装做了」，而是把每一步的**实际结果**打出来：
 *
 *   ✓ 做了的
 *   ⊘ 跳过的，以及**为什么**跳过
 *
 * 一个装完却说「安装完成」、实际后台进不去的命令，比没有这个命令更糟。
 *
 * ## 装完能做什么
 *
 *   1. 写 config/aimanong.php
 *   2. 在 app/service.php 里登记 ThinkService
 *   3. 然后就能：定义 Resource → `php think ai:verify` → `php think aimanong:schema`
 *
 * 数据 API（/admin/api/{uri}）与后台页面（/admin）已经可用；
 * **缺的是登录**——所以后台目前不需要登录就能访问，只适合本地开发。
 * 这一点必须说清楚，否则用户会以为已经可以上线。
 */
final class InstallCommand extends Command
{
    /**
     * 尚未在 ThinkPHP 驱动上实现的步骤。
     *
     * @var array<string, string>
     */
    public const NOT_IMPLEMENTED = [
        '建表' => '框架的数据表（管理员 / 角色 / 权限）随认证层一起提供，尚未移植',
        '创建管理员' => '认证未实现，现在建了也无法登录',
        '同步权限节点' => '权限目前由 config/aimanong.php 的 permissions 数组声明（默认拒绝）',
    ];

    protected function configure(): void
    {
        $this->setName('aimanong:install')
            ->addOption('force', null, Option::VALUE_NONE, '覆盖已存在的配置')
            ->addOption('dir', null, Option::VALUE_REQUIRED, '项目根目录，默认当前目录')
            ->setDescription('安装 Aimanong（ThinkPHP 版：仅脚手架，不建表/不建管理员）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $root = rtrim((string) ($input->getOption('dir') ?: getcwd()), '/');
        $force = (bool) $input->getOption('force');

        $output->writeln('');
        $output->writeln('<info>╔══════════════════════════════════════╗</info>');
        $output->writeln('<info>║   AI 码农 · Aimanong 安装程序         ║</info>');
        $output->writeln('<info>║   （ThinkPHP 版：仅脚手架）           ║</info>');
        $output->writeln('<info>╚══════════════════════════════════════╝</info>');
        $output->writeln('');

        $failed = 0;

        $failed += $this->publishConfig($output, $root, $force);
        $failed += $this->registerService($output, $root);
        $this->reportSkipped($output);

        $output->writeln('');
        $output->writeln('<comment>下一步：</comment>');
        $output->writeln('  1. 定义一个 Resource（继承 Aimanong\Resource）');
        $output->writeln('  2. 在 app/service.php 的服务里注册：Aimanong::registry()->register(XxxResource::class)');
        $output->writeln('  3. php think ai:verify          校验声明（幽灵列 / 字典 / 权限）');
        $output->writeln('  4. php think aimanong:schema    生成 JSON / TS / OpenAPI / AI 上下文');
        $output->writeln('  5. 打开 /admin 看后台，/admin/api/{uri} 取数据');
        $output->writeln('');
        $output->writeln('<comment>⚠️ 认证层尚未实现：后台目前无需登录即可访问，仅适合本地开发。</comment>');

        return $failed > 0 ? 1 : 0;
    }

    /**
     * 写 config/aimanong.php。
     */
    protected function publishConfig(Output $output, string $root, bool $force): int
    {
        $target = $root.'/config/aimanong.php';
        $source = __DIR__.'/../../config/aimanong.php';

        if (is_file($target) && ! $force) {
            $output->writeln('<comment>⊘ 已存在 config/aimanong.php，跳过（--force 可覆盖）</comment>');

            return 0;
        }

        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            $output->writeln("<error>✗ 无法创建 config 目录</error>");

            return 1;
        }

        if (! copy($source, $target)) {
            $output->writeln("<error>✗ 写入失败: {$target}</error>");

            return 1;
        }

        $output->writeln('<info>✓ 已发布配置文件 config/aimanong.php</info>');

        return 0;
    }

    /**
     * 在 app/service.php 里登记 ThinkService（幂等）。
     */
    protected function registerService(Output $output, string $root): int
    {
        $path = $root.'/app/service.php';
        $entry = '\\Aimanong\\ThinkPHP\\ThinkService::class';

        if (! is_file($path)) {
            if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
                $output->writeln('<error>✗ 无法创建 app 目录</error>');

                return 1;
            }

            file_put_contents($path, "<?php\n\nreturn [\n    {$entry},\n];\n");
            $output->writeln('<info>✓ 已创建 app/service.php 并登记 ThinkService</info>');

            return 0;
        }

        $content = (string) file_get_contents($path);

        if (str_contains($content, 'ThinkService')) {
            $output->writeln('<comment>⊘ app/service.php 已登记 ThinkService，跳过</comment>');

            return 0;
        }

        /*
         * 不猜用户服务的顺序 —— 直接插到数组第一项。
         *
         * ⚠️ 顺序有实际后果：ThinkService 的 register() 绑定框架运行时与驱动，
         * 排在它前面的服务调用 Aimanong::registry() 会抛未注册。
         * 所以**必须**是第一个。
         */
        $patched = preg_replace(
            '/return\s*\[\s*/',
            "return [\n    {$entry},\n",
            $content,
            1
        );

        if (! is_string($patched) || $patched === $content) {
            $output->writeln("<error>✗ 无法自动改写 app/service.php，请手工加上 {$entry}</error>");
            $output->writeln('    （必须放在数组**第一项**：它绑定的框架运行时是其它服务的前提）');

            return 1;
        }

        file_put_contents($path, $patched);
        $output->writeln('<info>✓ 已在 app/service.php 首项登记 ThinkService</info>');

        return 0;
    }

    /**
     * 把「没做的」明确说出来。
     *
     * 这是本命令与 Laravel 版最大的差别 —— 与其让用户以为都装好了，
     * 不如直接告诉他缺什么、为什么缺。
     */
    protected function reportSkipped(Output $output): void
    {
        $output->writeln('');
        $output->writeln('<comment>以下步骤在 ThinkPHP 驱动上尚未实现，已跳过：</comment>');

        foreach (self::NOT_IMPLEMENTED as $step => $why) {
            $output->writeln("  ⊘ {$step} —— {$why}");
        }
    }
}
