<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Aimanong\Auth\PermissionGate;
use Aimanong\Models\Administrator;
use Aimanong\Models\Permission;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

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
                            {action=sync : sync|list|super|migrate-permissions}
                            {user? : 用户名（action=super 时用）}
                            {--from= : 来源应用名（migrate-permissions 用，留空=旧的无前缀节点）}
                            {--to= : 目标应用名（migrate-permissions 用，必填）}
                            {--dry-run : 只报告不写入}';

    protected $description = '权限节点管理';

    public function handle(): int
    {
        $action = is_string($this->argument('action')) ? $this->argument('action') : 'sync';

        return match ($action) {
            'sync' => $this->sync(),
            'list' => $this->list(),
            'super' => $this->makeSuper(),
            'migrate-permissions' => $this->migratePermissions(),
            default => $this->fail("未知操作: {$action}（可用: sync / list / super）"),
        };
    }

    protected function sync(): int
    {
        $result = PermissionGate::sync();

        $this->info("✅ 权限已同步：新增 {$result['created']} 个，已存在 {$result['existing']} 个");

        return self::SUCCESS;
    }

    /**
     * 把旧的单应用权限迁移到多应用前缀节点。
     *
     * ## 为什么需要它
     *
     * 开多应用后，权限 slug 从 `orders.index` 变成 `admin.orders.index`。
     * 已有的角色授权（`admin_permission_role`）还挂在旧 slug 上 ——
     * 不迁移的话，所有人一升级就全部 403。
     *
     * ## 安全设计
     *
     * - **旧节点全部保留**（不删），这样回滚 = 关掉多应用即可
     * - 只做「新增节点 + 复制角色关联」
     * - 支持 --dry-run 先看会迁移多少
     */
    protected function migratePermissions(): int
    {
        $to = $this->option('to');

        if (! is_string($to) || $to === '') {
            return $this->fail('需要 --to=目标应用名');
        }

        $from = $this->option('from');
        $from = is_string($from) && $from !== '' ? $from : null;
        $dry = (bool) $this->option('dry-run');

        // 先为目标应用建节点（CLI 无请求上下文，所以显式指定）
        $sync = PermissionGate::sync($to);
        $this->info("已为 [{$to}] 同步节点：新增 {$sync['created']}，已存在 {$sync['existing']}");

        $prefix = $from !== null ? $from.'.' : '';

        /** @var Collection<int, Permission> $old */
        $old = Permission::query()
            ->when($from !== null, fn ($q) => $q->where('slug', 'like', $prefix.'%'))
            ->when($from === null, fn ($q) => $q->where('slug', 'not like', '%.%.%'))
            ->get();

        $mapped = 0;
        $skipped = 0;

        foreach ($old as $perm) {
            // 去掉来源前缀，得到基础 slug
            $base = $from !== null ? substr($perm->slug, strlen($prefix)) : $perm->slug;

            if ($base === '' || $base === false) {
                $skipped++;

                continue;
            }

            /*
             * ⚠️ 这里**不能**用 appSlug($to, $base) ——
             * 它的第二个参数是 **uri**，内部还会再拼一个动作（`...index`）。
             * 而 $base 已经是完整的 `orders.index`。
             * 传进去会得到 `admin.orders.index.index` —— 目标节点永远不存在，
             * 于是迁移静默 0 条（这个 bug 是拿真实角色数据验出来的）。
             */
            $newSlug = $to.'.'.$base;

            // 目标节点必须存在（上面 sync 已建；若 Resource 不属于该应用则不建）
            $target = Permission::query()->where('slug', $newSlug)->first();

            if ($target === null) {
                $skipped++;

                continue;
            }

            // 复制角色关联
            $roleIds = $perm->roles()->pluck('admin_roles.id')->all();

            if ($roleIds === []) {
                $skipped++;

                continue;
            }

            if (! $dry) {
                $target->roles()->syncWithoutDetaching($roleIds);
            }

            $mapped++;
            $this->line("  {$perm->slug} → {$newSlug}（".count($roleIds).' 个角色）');
        }

        $this->newLine();

        if ($dry) {
            $this->warn("--dry-run：未写入。将迁移 {$mapped} 个节点，跳过 {$skipped} 个");

            return self::SUCCESS;
        }

        $this->info("✅ 已迁移 {$mapped} 个节点的角色授权，跳过 {$skipped} 个（无角色或无目标节点）");
        $this->line('   旧节点保留未删 —— 关掉多应用即可回到原样');

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
