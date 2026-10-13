<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Console;

use Aimanong\ThinkPHP\Models\Administrator;
use Aimanong\ThinkPHP\Models\Role;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;

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
        '权限节点同步命令' => '按 Resource 声明批量写入权限节点的命令尚未移植；当前请手工维护 admin_permissions',
    ];

    protected function configure(): void
    {
        $this->setName('aimanong:install')
            ->addOption('force', null, Option::VALUE_NONE, '覆盖已存在的配置')
            ->addOption('dir', null, Option::VALUE_REQUIRED, '项目根目录，默认当前目录')
            ->addOption('admin-user', null, Option::VALUE_REQUIRED, '管理员账号（不传则不创建）')
            ->addOption('admin-password', null, Option::VALUE_REQUIRED, '管理员密码')
            ->addOption('no-admin', null, Option::VALUE_NONE, '跳过创建管理员账号')
            ->setDescription('安装 Aimanong（ThinkPHP 版：脚手架 + 建表 + 创建管理员）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $root = rtrim((string) ($input->getOption('dir') ?: getcwd()), '/');
        $force = (bool) $input->getOption('force');

        $output->writeln('');
        $output->writeln('<info>╔══════════════════════════════════════╗</info>');
        $output->writeln('<info>║   AI 码农 · Aimanong 安装程序         ║</info>');

        $output->writeln('<info>╚══════════════════════════════════════╝</info>');
        $output->writeln('');

        $failed = 0;

        $failed += $this->publishConfig($output, $root, $force);
        $failed += $this->registerService($output, $root);
        $failed += $this->migrate($output);
        $failed += $this->createAdmin($output, $input);
        $this->reportSkipped($output);

        $output->writeln('');
        $output->writeln('<comment>下一步：</comment>');
        $output->writeln('  1. 定义一个 Resource（继承 Aimanong\Resource）');
        $output->writeln('  2. 在 app/service.php 的服务里注册：Aimanong::registry()->register(XxxResource::class)');
        $output->writeln('  3. php think ai:verify          校验声明（幽灵列 / 字典 / 权限）');
        $output->writeln('  4. php think aimanong:schema    生成 JSON / TS / OpenAPI / AI 上下文');
        $output->writeln('  5. 打开 /admin 看后台，/admin/api/{uri} 取数据');
        $output->writeln('');
        $output->writeln('<comment>说明：登录限流（两个维度）已启用；RBAC 表与超管角色已就绪。</comment>');
        $output->writeln('<comment>      要启用 RBAC：config/aimanong.php → auth.rbac = true。</comment>');

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
            $output->writeln('<error>✗ 无法创建 config 目录</error>');

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
     * 建框架需要的数据表。
     *
     * ThinkPHP 没有内置迁移系统（think-migration 是独立包），
     * 所以这里直接用 DDL。用 `CREATE TABLE IF NOT EXISTS` 保证幂等。
     */
    protected function migrate(Output $output): int
    {
        $driver = $this->dbDriver();
        $isSqlite = $driver === 'sqlite';

        $id = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $json = $isSqlite ? 'TEXT' : 'JSON';

        $sql = "CREATE TABLE IF NOT EXISTS admin_users (
            id {$id},
            username VARCHAR(190) NOT NULL,
            name VARCHAR(255) NULL,
            password VARCHAR(255) NOT NULL,
            avatar VARCHAR(255) NULL,
            enabled TINYINT NOT NULL DEFAULT 1,
            remember_token VARCHAR(100) NULL,
            preferences {$json} NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE (username)
        )";

        $bigint = $isSqlite ? 'INTEGER' : 'BIGINT UNSIGNED';

        $rbac = [
            "CREATE TABLE IF NOT EXISTS admin_roles (
                id {$id},
                slug VARCHAR(100) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                is_super TINYINT NOT NULL DEFAULT 0,
                sort INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE (slug)
            )",
            "CREATE TABLE IF NOT EXISTS admin_permissions (
                id {$id},
                slug VARCHAR(190) NOT NULL,
                name VARCHAR(255) NOT NULL,
                `group` VARCHAR(255) NULL,
                description TEXT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE (slug)
            )",
            "CREATE TABLE IF NOT EXISTS admin_permission_role (
                role_id {$bigint} NOT NULL,
                permission_id {$bigint} NOT NULL,
                PRIMARY KEY (role_id, permission_id)
            )",
            "CREATE TABLE IF NOT EXISTS admin_role_user (
                role_id {$bigint} NOT NULL,
                user_id {$bigint} NOT NULL,
                PRIMARY KEY (role_id, user_id)
            )",
        ];

        try {
            Db::execute($sql);

            foreach ($rbac as $ddl) {
                Db::execute($ddl);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>✗ 建表失败: '.$e->getMessage().'</error>');

            return 1;
        }

        $output->writeln('<info>✓ 已创建数据表：admin_users / admin_roles / admin_permissions / 两张关联表</info>');

        return 0;
    }

    protected function dbDriver(): string
    {
        try {
            /** @var mixed $default */
            $default = Config::get('database.default');

            /** @var mixed $type */
            $type = Config::get('database.connections.'.(string) $default.'.type');

            return is_string($type) && $type !== '' ? $type : (string) $default;
        } catch (\Throwable) {
            return 'mysql';
        }
    }

    /**
     * 创建第一个管理员。
     *
     * ⚠️ 没有账号是**闭环死锁**：能打开后台却登不进去，
     * 而唯一的出路是手工写 SQL —— 那不在任何文档里。
     */
    protected function createAdmin(Output $output, Input $input): int
    {
        if ((bool) $input->getOption('no-admin')) {
            $output->writeln('<comment>⊘ 按要求跳过创建管理员（--no-admin）</comment>');
            $output->writeln('    ⚠️ 没有账号就登不进去，请稍后自行创建。');

            return 0;
        }

        $model = Administrator::class;

        try {
            if ($model::count() > 0) {
                $output->writeln('<comment>⊘ 已存在管理员账号，跳过创建（不会重置已有密码）</comment>');

                return 0;
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>✗ 查询管理员失败: '.$e->getMessage().'</error>');

            return 1;
        }

        $username = (string) ($input->getOption('admin-user') ?: 'admin');
        $password = (string) ($input->getOption('admin-password') ?: bin2hex(random_bytes(6)));

        try {
            $user = new $model;
            $user->save([
                'username' => $username,
                'name' => '管理员',
                'password' => $model::hashPassword($password),
                'enabled' => 1,
            ]);
        } catch (\Throwable $e) {
            $output->writeln('<error>✗ 创建管理员失败: '.$e->getMessage().'</error>');

            return 1;
        }

        $output->writeln("<info>✓ 已创建管理员：{$username}</info>");

        if ($input->getOption('admin-password') === null) {
            $output->writeln("    <comment>随机密码: {$password}（请立即保存）</comment>");
        }

        $this->ensureSuperRole($output, $user);

        return 0;
    }

    /**
     * 保证存在「超级管理员」角色，并把刚建的管理员挂上去。
     *
     * ## 为什么必须有
     *
     * RBAC 一打开，**没有角色 = 没有任何权限 = 全站 403**，
     * 包括管理后台本身。使用者会看到一个完全打不开的后台，
     * 而唯一的出路是手工往关联表插数据 —— 那不在任何文档里。
     * 这与「装完没有账号可登」是同一类闭环死锁。
     */
    protected function ensureSuperRole(Output $output, Administrator $user): void
    {
        try {
            $roleModel = Role::class;
            $role = $roleModel::where('slug', 'admin')->find();

            if ($role === null) {
                $role = new $roleModel;
                $role->save([
                    'slug' => 'admin',
                    'name' => '超级管理员',
                    'description' => '绕过全部权限判定',
                    'is_super' => 1,
                    'sort' => 0,
                ]);

                $output->writeln('<info>✓ 已创建超级管理员角色</info>');
            }

            // 绑定用户 ↔ 角色（先查后插，保证幂等）
            $exists = Db::name('admin_role_user')
                ->where('role_id', $role->getKey())
                ->where('user_id', $user->getKey())
                ->find();

            if ($exists === null) {
                Db::name('admin_role_user')->insert([
                    'role_id' => $role->getKey(),
                    'user_id' => $user->getKey(),
                ]);

                $output->writeln('<info>✓ 已把管理员挂到超级管理员角色</info>');
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>✗ 创建超级管理员角色失败: '.$e->getMessage().'</error>');
            $output->writeln('    ⚠️ RBAC 打开后没有角色会全站 403，请手工处理。');
        }
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
