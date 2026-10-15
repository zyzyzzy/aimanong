<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Console;

use Aimanong\ThinkPHP\ThinkPermissionChecker;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * 权限节点同步（ThinkPHP 版）—— `aimanong:permission sync`。
 *
 * 与 Laravel 侧同名同语义，这样 CI / AI 的工具链不用按框架分叉。
 *
 * ## 为什么补这个命令
 *
 * 在此之前 ThinkPHP 驱动**完全没有**权限节点的同步入口。后果不是
 * 「少个命令」，而是 **RBAC 在 TP 上实际不可用**：节点永远建不出来，
 * 于是除超管外所有人访问任何页面都是 403。
 *
 * 而这**在测试里看不出来** —— e2e 断言的是「普通角色被拒」，
 * 而「权限系统正常工作」与「谁都没有权限」产生的结果完全一样。
 * 直到要验证「按钮级权限」时需要给一个非超管账号**开**某个权限，
 * 才发现根本没有节点可开。
 */
final class PermissionCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('aimanong:permission')
            ->addArgument('action', Argument::OPTIONAL, '动作（目前只有 sync）', 'sync')
            ->addOption('app', null, Option::VALUE_OPTIONAL, '只为某个应用建节点（默认按上下文）')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出（AI/CI 友好）')
            ->setDescription('权限节点管理（sync：把 Resource 的动作写进库）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = (string) $input->getArgument('action');
        $isJson = (bool) $input->getOption('json');

        if ($action !== 'sync') {
            $output->writeln("不支持的动作用 [{$action}]，目前只有 sync。");

            return 1;
        }

        $app = $input->getOption('app');
        $result = (new ThinkPermissionChecker)->sync(is_string($app) && $app !== '' ? $app : null);

        if ($isJson) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $output->writeln(sprintf(
            '✅ 权限已同步：新增 %d 个，已存在 %d 个',
            $result['created'],
            $result['existing']
        ));

        if ($result['orphaned'] > 0) {
            // 与 Laravel 侧一致：**故意不自动删** —— 那是用户已配给角色的授权
            $output->writeln(sprintf(
                '   注意：有 %d 个孤儿节点（库里存在但已无对应 Resource/应用）。'
                .'不会自动删，需要时请手工清理。',
                $result['orphaned']
            ));
        }

        return 0;
    }
}
