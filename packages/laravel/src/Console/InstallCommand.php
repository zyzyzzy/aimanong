<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Aimanong\Auth\PermissionGate;
use Aimanong\Models\Administrator;
use Aimanong\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * 安装命令。
 *
 * Laravel 11+ 骨架变更要点：
 *   - config/app.php 不再有 providers 数组
 *   - 新增 bootstrap/providers.php
 *   - 必须改用 ServiceProvider::addProviderToBootstrapFile()
 */
class InstallCommand extends Command
{
    protected $signature = 'aimanong:install
                            {--force : 强制执行，覆盖已存在的配置}
                            {--admin-user= : 管理员账号（不传则交互式询问）}
                            {--admin-password= : 管理员密码（不传则交互式询问或随机生成）}
                            {--no-admin : 跳过创建管理员账号}';

    protected $description = '安装 AI 码农后台框架';

    public function handle(): int
    {
        $this->info('╔══════════════════════════════════════╗');
        $this->info('║   AI 码农 · Aimanong 安装程序         ║');
        $this->info('╚══════════════════════════════════════╝');
        $this->newLine();

        $this->registerProvider();
        $this->publishAssets();
        $this->runMigrations();

        /*
         * ⚠️ 必须创建管理员账号 —— 否则这是一个**闭环死锁**：
         *
         *   装完 → admin_users 表为空 → 打开后台跳登录页
         *        → 没有账号可登 → 进不去
         *        → 也就无法用「管理员账号」界面创建第一个账号
         *
         * 使用者唯一的出路是手工执行 tinker 脚本，而那不在任何文档里。
         * （这正是本文档写作时的真实情况，见 internal-docs/文档与易用性评估.md）
         */
        $admin = $this->createAdmin();

        /*
         * 同步权限节点。
         *
         * 内置的五个 Resource（管理员 / 操作日志 / 登录日志 /
         * 数据字典 / 字典条目）注册后会**自动推导**出权限节点名，
         * 但必须执行 sync 才写入数据库。
         *
         * 不 sync 的后果：RBAC 开启时这些菜单在权限配置界面里
         * 根本看不到，管理员无法把它们分配给任何角色。
         * （超管绕过判定，所以第一个账号不受影响 —— 但下一个账号就会。）
         */
        $this->syncPermissions();

        $this->newLine();
        $this->info('✅ 安装完成');

        // 安装命令在 CLI 下运行，无请求上下文，直接读 config（正确行为）
        $prefix = config('aimanong.route.prefix', 'admin');
        $prefix = is_string($prefix) ? $prefix : 'admin';

        $this->newLine();
        $this->line('  访问地址: /'.$prefix);

        if ($admin !== null) {
            $this->line('  登录账号: '.$admin['username']);
            $this->line('  登录密码: '.$admin['password']);
            $this->newLine();
            $this->warn('  ⚠️ 请立即登录并修改密码（个人中心 → 修改密码）');
        } elseif ($this->option('no-admin')) {
            $this->warn('  已跳过创建管理员账号（--no-admin）');
            $this->line('  稍后可执行: php artisan aimanong:install --force 补建');
        }

        return self::SUCCESS;
    }

    /**
     * 创建第一个管理员账号（幂等）。
     *
     * ## 为什么必须做
     *
     * 没有它，全新项目装完就进不去后台 —— 而且**没有别的出路**：
     * 后台的「管理员账号」界面本身就需要先登录才能打开。
     *
     * ## 幂等
     *
     * 已有账号时**不重复创建、不改密码**（避免重跑安装把用户改过的
     * 密码重置掉），只提示如何手动补建。
     *
     * @return array{username: string, password: string}|null
     */
    protected function createAdmin(): ?array
    {
        if ($this->option('no-admin')) {
            return null;
        }

        $model = config('aimanong.auth.model', Administrator::class);

        if (! is_string($model) || ! class_exists($model)) {
            $this->warn('  跳过创建管理员：无法解析 auth.model');

            return null;
        }

        // 迁移是交互式可跳过的，表不存在时不能崩
        try {
            /** @var Model $probe */
            $probe = new $model;
            $table = $probe->getTable();

            if (! Schema::hasTable($table)) {
                $this->warn('  跳过创建管理员：数据表 '.$table.' 不存在（请先执行 migrate）');

                return null;
            }

            if ($model::query()->exists()) {
                $this->info('✓ 已存在管理员账号，跳过创建（不会重置已有密码）');
                $this->line('   如需新增：登录后台 → 系统 → 管理员');

                return null;
            }
        } catch (\Throwable $e) {
            $this->warn('  跳过创建管理员：'.Str::limit($e->getMessage(), 80));

            return null;
        }

        $this->newLine();
        $this->line('── 创建第一个管理员账号 ──');

        $username = $this->option('admin-user');
        $username = is_string($username) && $username !== ''
            ? $username
            : (string) $this->ask('账号', 'admin');

        $password = $this->option('admin-password');

        if (! is_string($password) || $password === '') {
            /*
             * 默认**随机强密码**而不是 admin123。
             *
             * 安装命令常被复制到脚本 / CI 里跑，固定弱密码等于
             * 给每个新项目留一个公开的后门。
             */
            $generated = Str::password(16);

            $input = (string) $this->ask('密码（直接回车则使用随机强密码）', '');

            if ($input === '') {
                $password = $generated;
                $this->line('  已生成随机密码');
            } else {
                $password = $input;
            }
        }

        try {
            /** @var Model $user */
            $user = new $model;
            $user->forceFill([
                'username' => $username,
                'name' => '超级管理员',
                'password' => $password,
                'enabled' => true,
            ])->save();

            $this->assignSuperRole($user);

            $this->info('✓ 管理员账号已创建');

            return ['username' => $username, 'password' => $password];
        } catch (\Throwable $e) {
            $this->error('  创建管理员失败：'.$e->getMessage());
            $this->line('   请检查数据库权限，或手动创建后重试');

            return null;
        }
    }

    /**
     * 同步权限节点到数据库。
     *
     * 失败不阻断安装 —— 权限可以稍后手动 sync。
     */
    protected function syncPermissions(): void
    {
        try {
            $result = PermissionGate::sync();

            $this->info(sprintf(
                '✓ 权限节点已同步（新增 %d，已存在 %d）',
                $result['created'],
                $result['existing']
            ));
        } catch (\Throwable $e) {
            $this->warn('  权限节点同步失败（可稍后手动执行 aimanong:permission sync）：'
                .Str::limit($e->getMessage(), 60));
        }
    }

    /**
     * 给新账号绑定超管角色。
     *
     * 不绑定的话，登进去会**什么都看不到**（RBAC 开启时菜单按权限过滤）——
     * 对使用者来说和「进不去」差别不大。
     */
    protected function assignSuperRole(object $user): void
    {
        try {
            /** @var class-string $roleModel */
            $roleModel = Role::class;

            $role = $roleModel::query()->firstOrCreate(
                ['slug' => 'super_admin'],
                ['name' => '超级管理员', 'description' => '绕过全部权限判定', 'is_super' => true]
            );

            if (method_exists($user, 'roles')) {
                $user->roles()->syncWithoutDetaching([$role->getKey()]);
                $this->info('✓ 已绑定超级管理员角色');
            }
        } catch (\Throwable $e) {
            // 绑角色失败不该让安装整体失败 —— 账号已经建好了
            $this->warn('  角色绑定失败（可登录后在后台手动分配）：'.Str::limit($e->getMessage(), 60));
        }
    }

    /**
     * 注册服务提供者到 bootstrap/providers.php。
     */
    protected function registerProvider(): void
    {
        $provider = 'Aimanong\\AimanongServiceProvider';

        // Laravel 11+ 起 config/app.php 不再有 providers 数组，
        // 必须写入 bootstrap/providers.php
        ServiceProvider::addProviderToBootstrapFile($provider);
        $this->info('✓ 已注册服务提供者到 bootstrap/providers.php');
    }

    protected function publishAssets(): void
    {
        $this->callSilent('vendor:publish', [
            '--tag' => 'aimanong-config',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->info('✓ 已发布配置文件 config/aimanong.php');

        $this->callSilent('vendor:publish', [
            '--tag' => 'aimanong-migrations',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->info('✓ 已发布数据库迁移');
    }

    protected function runMigrations(): void
    {
        if (! $this->confirm('是否立即执行数据库迁移？', true)) {
            $this->line('  稍后手动执行: php artisan migrate');

            return;
        }

        Artisan::call('migrate', ['--force' => true]);
        $this->info('✓ 数据库迁移完成');
    }
}
