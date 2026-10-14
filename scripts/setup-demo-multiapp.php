<?php

declare(strict_types=1);

/*
 * 给 demo 项目配置「多应用（三后台）」。
 *
 * ## 为什么要在 setup 脚本里配，而不是只在我本机配
 *
 * e2e 里有多应用隔离断言（各自登录页、菜单不串台）。若 demo 不配多应用，
 * 这些断言在 CI 里只会「跳过」—— 那就退化成「CI 永远测不到多应用」，
 * 正是本项目已经吃过亏的模式（仓内路径 ≠ 用户路径）。
 *
 * ## 幂等
 *
 * 可重复执行：已存在的文件/配置不重复写。
 *
 * 用法（在 demo 项目根目录）：php /path/to/setup-demo-multiapp.php
 */

$root = getcwd();

if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "❌ 当前目录不是 Laravel 项目（找不到 artisan）: {$root}\n");
    exit(1);
}

// ─────────────────────────────────────────────
// 1. Agent 模型
// ─────────────────────────────────────────────
$agentModel = <<<'PHP'
<?php

namespace App\Models;

use Aimanong\Concerns\HasAimanongPermissions;
use Aimanong\Contracts\Permissible;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * 代理商（代理商后台的登录用户）。
 */
class Agent extends Authenticatable implements Permissible
{
    use HasAimanongPermissions;

    protected $table = 'agents';

    protected $fillable = ['username', 'name', 'password', 'company', 'enabled'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
PHP;

file_put_contents($root.'/app/Models/Agent.php', $agentModel."\n");
echo "  ✅ app/Models/Agent.php\n";

// ─────────────────────────────────────────────
// 2. User 模型接入权限体系
// ─────────────────────────────────────────────
$userPath = $root.'/app/Models/User.php';
$user = (string) file_get_contents($userPath);

if (! str_contains($user, 'HasAimanongPermissions')) {
    $user = str_replace(
        'use Illuminate\Notifications\Notifiable;',
        "use Aimanong\\Concerns\\HasAimanongPermissions;\nuse Aimanong\\Contracts\\Permissible;\nuse Illuminate\\Notifications\\Notifiable;",
        $user
    );
    $user = str_replace(
        'class User extends Authenticatable',
        'class User extends Authenticatable implements Permissible',
        $user
    );
    $user = str_replace(
        'use HasFactory, Notifiable;',
        'use HasFactory, Notifiable, HasAimanongPermissions;',
        $user
    );
    file_put_contents($userPath, $user);
    echo "  ✅ app/Models/User.php 已接入 Permissible\n";
}

// ─────────────────────────────────────────────
// 3. 迁移：agents 表 + users.username
// ─────────────────────────────────────────────
$migrations = [
    '2026_10_14_170303_create_agents_table.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agents')) {
            return;
        }

        Schema::create('agents', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 190)->unique();
            $table->string('name')->nullable();
            $table->string('password');
            $table->string('company')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
PHP,
    '2026_10_14_170400_add_username_to_users.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'username')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            // 后台用户体系统一用 username 登录（Aimanong 约定）
            $table->string('username', 190)->nullable()->unique()->after('id');

            /*
             * `enabled` —— 权限判定要用（禁用账号即使密码正确也不放行）。
             *
             * ⚠️ 全新 Laravel 骨架的 users 表**没有**这一列。
             * demo 里建的是普通用户账号，也要受「停用即拒绝」约束，
             * 否则多应用下「停用」对普通用户就是装饰。
             */
            if (! Schema::hasColumn('users', 'enabled')) {
                $table->boolean('enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);

            if (Schema::hasColumn('users', 'enabled')) {
                $table->dropColumn('enabled');
            }

            $table->dropColumn('username');
        });
    }
};
PHP,
];

foreach ($migrations as $name => $body) {
    $path = $root.'/database/migrations/'.$name;
    file_put_contents($path, $body."\n");
    echo "  ✅ database/migrations/{$name}\n";
}

// ─────────────────────────────────────────────
// 4. config/auth.php —— 三个 guard + provider
// ─────────────────────────────────────────────
$authPath = $root.'/config/auth.php';
$auth = (string) file_get_contents($authPath);

if (! str_contains($auth, "'agent' =>")) {
    $auth = str_replace(
        "        'web' => [\n            'driver' => 'session',\n            'provider' => 'users',\n        ],\n    ],",
        "        'web' => [\n            'driver' => 'session',\n            'provider' => 'users',\n        ],\n\n        // 多应用：每个后台一个 guard\n        'admin' => [\n            'driver' => 'session',\n            'provider' => 'admin',\n        ],\n\n        'agent' => [\n            'driver' => 'session',\n            'provider' => 'agent',\n        ],\n\n        'user' => [\n            'driver' => 'session',\n            'provider' => 'users',\n        ],\n    ],",
        $auth
    );

    $auth = str_replace(
        "        'users' => [\n            'driver' => 'eloquent',\n            'model' => env('AUTH_MODEL', User::class),\n        ],",
        "        'users' => [\n            'driver' => 'eloquent',\n            'model' => env('AUTH_MODEL', User::class),\n        ],\n\n        // 多应用：每个后台一个 provider\n        'admin' => [\n            'driver' => 'eloquent',\n            'model' => \\Aimanong\\Models\\Administrator::class,\n        ],\n\n        'agent' => [\n            'driver' => 'eloquent',\n            'model' => \\App\\Models\\Agent::class,\n        ],",
        $auth
    );

    file_put_contents($authPath, $auth);
    echo "  ✅ config/auth.php 已加 guard/provider\n";
}

// ─────────────────────────────────────────────
// 5. config/aimanong.php —— applications
// ─────────────────────────────────────────────
$cfgPath = $root.'/config/aimanong.php';
$cfg = (string) file_get_contents($cfgPath);

if (! str_contains($cfg, "'applications' => [\n        'admin'")) {
    $cfg = preg_replace(
        "/'applications' => \[\],/",
        "'applications' => [\n        'admin' => [\n            'title' => '总管理后台',\n            'route' => ['prefix' => 'admin'],\n            'auth' => ['guard' => 'admin', 'model' => \\Aimanong\\Models\\Administrator::class],\n        ],\n        'user' => [\n            'title' => '用户后台',\n            'route' => ['prefix' => 'user'],\n            'auth' => ['guard' => 'user', 'model' => \\App\\Models\\User::class],\n        ],\n        'agent' => [\n            'title' => '代理商后台',\n            'route' => ['prefix' => 'agent'],\n            'auth' => ['guard' => 'agent', 'model' => \\App\\Models\\Agent::class],\n        ],\n    ],",
        $cfg,
        1
    );
    file_put_contents($cfgPath, $cfg);
    echo "  ✅ config/aimanong.php 已配三个后台\n";
}

// RBAC 必须打开，否则权限判定恒放行 —— 菜单隔离就测不出来了
if (preg_match("/'rbac' => (false|env\('AIMANONG_RBAC', false\))/", $cfg)) {
    $cfg = (string) preg_replace(
        "/'rbac' => (false|env\('AIMANONG_RBAC', false\))/",
        "'rbac' => true",
        $cfg,
        1
    );
    file_put_contents($cfgPath, $cfg);
    echo "  ✅ RBAC 已开启（多应用隔离验证需要）\n";
}

// ─────────────────────────────────────────────
// 6. Resource 归属
// ─────────────────────────────────────────────
$adminOnly = [
    'AdminUserResource', 'AdminRoleResource', 'AdminPermissionResource',
    'LoginLogResource', 'OperationLogResource', 'DictTypeResource', 'DictItemResource',
];

$dir = $root.'/app/Aimanong';
$declared = 0;

if (is_dir($dir)) {
    foreach (scandir($dir) ?: [] as $f) {
        if (! str_ends_with($f, 'Resource.php')) {
            continue;
        }

        $path = $dir.'/'.$f;
        $src = (string) file_get_contents($path);

        if (str_contains($src, 'function applications')) {
            continue;
        }

        $apps = in_array(substr($f, 0, -4), $adminOnly, true) ? "['admin']" : "['admin', 'agent']";
        $decl = "\n    /**\n     * 属于哪些后台。\n     */\n    public static function applications(): array\n    {\n        return {$apps};\n    }\n";

        if (preg_match('/(    public static function menu\(\): array\n    \{.*?\n    \}\n)/s', $src, $m)) {
            $src = str_replace($m[1], $m[1].$decl, $src);
        } else {
            $i = strrpos($src, '}');
            $src = substr($src, 0, (int) $i).$decl.substr($src, (int) $i);
        }

        file_put_contents($path, $src);
        $declared++;
    }
}

if ($declared === 0) {
    echo "  ℹ️  未发现 app/Aimanong/*Resource.php（全新 demo 的常态）——\n";
    echo "      框架内置 Resource（管理员/角色/权限/字典/日志）在包内已声明 ['admin']，\n";
    echo "      多应用隔离断言仍可验证。\n";
} else {
    echo "  ✅ {$declared} 个 Resource 已声明归属\n";
}

// ─────────────────────────────────────────────
// 7. 迁移 + 种子 + 授权 + 同步
// ─────────────────────────────────────────────
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
echo "  ✅ 迁移完成\n";

/*
 * ⚠️ 迁移是**一次性**的：若某个迁移文件后来改了内容，`migrate` 不会重跑，
 * 列依然缺失。所以这里在写入前再兜一次 —— 让脚本对「迁移已记录但列不存在」
 * 也健壮（本脚本要能反复跑）。
 */
$schema = Illuminate\Support\Facades\Schema::class;

if (! $schema::hasColumn('users', 'enabled')) {
    $schema::table('users', function ($table): void {
        $table->boolean('enabled')->default(true);
    });
    echo "  ✅ users.enabled 已补（迁移未覆盖到）\n";
}

$hash = Illuminate\Support\Facades\Hash::class;

// 代理商账号
$agent = \App\Models\Agent::query()->firstOrNew(['username' => 'agent1']);
$agent->name = '代理A';
$agent->company = '华北代理';
$agent->password = 'agent123';          // casts: hashed → 自动哈希
$agent->enabled = true;
$agent->save();

// 用户账号
$userRow = \App\Models\User::query()->firstOrNew(['username' => 'user01']);
$userRow->name = '普通用户';
$userRow->email = $userRow->email ?: 'user@example.com';
$userRow->password = $hash::make('user123');
$userRow->enabled = true;
$userRow->save();

echo "  ✅ 账号就绪：agent1/agent123 · user01/user123\n";

// 角色与授权（幂等）
$sync = Illuminate\Support\Facades\Artisan::call('aimanong:permission', ['action' => 'sync']);
echo "  ✅ 权限节点已同步\n";

$roleModel = \Aimanong\Models\Role::class;

$agentRole = $roleModel::query()->firstOrCreate(
    ['slug' => 'agent_role'],
    ['name' => '代理商角色', 'is_super' => false]
);
$perms = \Aimanong\Models\Permission::query()
    ->where('slug', 'like', 'agent.crm-customers.%')
    ->pluck('id')
    ->all();
$agentRole->permissions()->syncWithoutDetaching($perms);
$agent->aimanongRoles()->syncWithoutDetaching([$agentRole->id]);

$userRole = $roleModel::query()->firstOrCreate(
    ['slug' => 'user_role'],
    ['name' => '普通用户', 'is_super' => false]
);
$userRow->aimanongRoles()->syncWithoutDetaching([$userRole->id]);

echo '  ✅ 授权完成：agent1 → '.count($perms)." 个节点；user01 → 无权限（用于验证「没授权就什么都看不到」）\n";
