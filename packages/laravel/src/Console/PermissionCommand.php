<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Aimanong\Auth\PermissionGate;
use Aimanong\Models\Administrator;
use Illuminate\Console\Command;

/**
 * 权限管理命令。
 *
 *   php artisan aimanong:permission sync     # 同步权限节点（Resource → 权限）
 *   php artisan aimanong:permission list     # 列出全部权限
 *   php artisan aimanong:permission super {user}  # 把用户设为超级管理员
 */
class PermissionCommand extends Command
{
    protected $signature = 'aimanong:permission
                            {action=sync : sync|list|super}
                            {user? : 用户名（action=super 时用）}';

    protected $description = '权限节点管理';

    public function handle(): int
    {
        $action = is_string($this->argument('action')) ? $this->argument('action') : 'sync';

        return match ($action) {
            'sync' => $this->sync(),
            'list' => $this->list(),
            'super' => $this->makeSuper(),
            default => $this->fail("未知操作: {$action}（可用: sync / list / super）"),
        };
    }

    protected function sync(): int
    {
        $result = PermissionGate::sync();

        $this->info("✅ 权限已同步：新增 {$result['created']} 个，已存在 {$result['existing']} 个");

        return self::SUCCESS;
    }

    protected function list(): int
    {
        $all = PermissionGate::all();

        if ($all === []) {
            $this->warn('暂无权限节点，请先运行 sync');

            return self::SUCCESS;
        }

        $rows = array_map(static fn (array $p): array => [
            $p['group'],
            $p['slug'],
            $p['name'],
        ], $all);

        $this->table(['分组', '权限标识', '名称'], $rows);
        $this->line('共 '.count($all).' 个权限节点');

        return self::SUCCESS;
    }

    protected function makeSuper(): int
    {
        $username = is_string($this->argument('user')) ? $this->argument('user') : '';

        if ($username === '') {
            $this->error('请指定用户名：php artisan aimanong:permission super admin');

            return self::FAILURE;
        }

        $user = Administrator::query()->where('username', $username)->first();

        if ($user === null) {
            $this->error("用户不存在: {$username}");

            return self::FAILURE;
        }

        PermissionGate::ensureSuperRole($user);

        $this->info("✅ {$username} 已是超级管理员");

        return self::SUCCESS;
    }
}
