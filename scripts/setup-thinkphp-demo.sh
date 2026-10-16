#!/usr/bin/env bash
#
# 从零搭一个 ThinkPHP 项目并装上本仓库的 aimanong/thinkphp。
#
# 用途：`tests/e2e/thinkphp-e2e.php` 的场地，以及在 CI 里证明
# **一个新 ThinkPHP 项目装上框架后，后台页面真的能打开**。
#
# 为什么要有它：Laravel 侧有 setup-demo.sh + http-e2e，ThinkPHP 侧此前
# **一条端到端都没有** —— /admin 200 只在本机验过一次。
# 按这个项目的教训：只在某台机器上验过的链路，等于没验。
#
# 用法：bash scripts/setup-thinkphp-demo.sh [目标目录]
#       默认 $ROOT/../thinkphp-demo

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-$ROOT/../thinkphp-demo}"

GREEN='\033[0;32m'; RED='\033[0;31m'; BOLD='\033[1m'; NC='\033[0m'
[ -t 1 ] || { GREEN=''; RED=''; BOLD=''; NC=''; }

printf "${BOLD}搭建 ThinkPHP 演示项目${NC} → %s\n" "$TARGET"

# 幂等：每次都重建，避免上一次的残留掩盖问题
rm -rf "$TARGET"
mkdir -p "$TARGET"/{app,config,public,runtime,route}

cat > "$TARGET/composer.json" <<JSON
{
    "name": "aimanong/thinkphp-demo",
    "description": "Aimanong ThinkPHP 端到端验证项目（由 scripts/setup-thinkphp-demo.sh 生成）",
    "require": {
        "php": "^8.2",
        "topthink/framework": "^8.0",
        "topthink/think-template": "^3.0",
        "aimanong/thinkphp": "@dev"
    },
    "repositories": [
        { "type": "path", "url": "$ROOT/packages/thinkphp", "options": { "symlink": true } },
        { "type": "path", "url": "$ROOT/packages/core", "options": { "symlink": true } }
    ],
    "autoload": { "psr-4": { "app\\\\": "app/" } },
    "minimum-stability": "stable",
    "prefer-stable": true
}
JSON

cat > "$TARGET/.env" <<'ENVEOF'
APP_DEBUG = true
DB_DRIVER = sqlite
DB_TYPE = sqlite
ENVEOF

cat > "$TARGET/public/index.php" <<'PHPEOF'
<?php
// 最小 TP 入口 —— 与真实项目一致：注册服务 → 跑 HTTP 内核
require __DIR__.'/../vendor/autoload.php';

$http = (new \think\App())->http;

$response = $http->run();
$response->send();
$http->end($response);
PHPEOF

# Resource 声明 —— 与 Laravel 侧**同一套写法**。
# 只有下面那行注册是框架胶水，grid/form/show 全是框架无关的。
cp "$ROOT/scripts/tp-demo-resource.php.tpl" "$TARGET/app/CustomerResource.php"
# ⚠️ 必须独立文件：PSR-4 要求类名与文件名一致，
#    把两个类塞进一个文件会让第二个类加载不到（注册表静默记一条失败）
cp "$ROOT/scripts/tp-demo-readonly.php.tpl" "$TARGET/app/ReadonlyCustomerResource.php"

cat > "$TARGET/app/AimanongDemoService.php" <<'PHPEOF'
<?php
namespace app;

use Aimanong\Aimanong;
use think\Service;

/**
 * 演示：在 ThinkPHP 上注册 Resource。
 *
 * 与 Laravel 侧的 `AimanongResourceProvider` 是同一行调用 ——
 * 这正是「同一套声明，两个框架」的意思。
 */
class AimanongDemoService extends Service
{
    public function register(): void
    {
        Aimanong::registry()->register(CustomerResource::class);
        Aimanong::registry()->register(ReadonlyCustomerResource::class);
        Aimanong::registry()->register(DepartmentResource::class);
        Aimanong::registry()->register(ShipOrderResource::class);
    }
}
PHPEOF

cat > "$TARGET/think" <<'PHPEOF'
#!/usr/bin/env php
<?php
// 控制台入口 —— 与 Laravel 的 artisan 对应
require __DIR__.'/vendor/autoload.php';

(new \think\App())->console->run();
PHPEOF
chmod +x "$TARGET/think"

mkdir -p "$TARGET/app/Extensions"

cat > "$TARGET/app/Extensions/DemoExtension.php" <<'PHPEOF'
<?php

namespace app\Extensions;

use Aimanong\Extend\Extension;
use Aimanong\Form\Fields\Text;

/**
 * TP 侧扩展机制验证用扩展。
 *
 * ⚠️ 这个类**故意不碰任何框架 facade**（路由体除外）——
 * 它验证的是「框架无关的那部分机制在 TP 上能不能跑」：
 *
 *   · register() / boot() 时序
 *   · field() 注入自定义字段类型（core 的 FieldType，两端共用）
 *   · routes() 走平台（回调体里用 TP 自己的 Route facade）
 *   · 路由挂在 /<后台前缀>/extensions/<扩展名>/ 下，并带鉴权中间件
 *
 * e2e 会断言 `demo_text` 出现在 capabilities 的 field_types 里、
 * 且 /admin/extensions/demo/ping 返回 200。
 */
class DemoExtension extends Extension
{
    public function name(): string
    {
        return 'demo';
    }

    public function title(): string
    {
        return '验证用扩展';
    }

    public function register(): void
    {
        $this->field(Text::class, 'demo_text', 'string', 'string');
    }

    public function boot(): void
    {
        $this->routes(function (): void {
            \think\facade\Route::get('ping', function () {
                return json(['pong' => true, 'ext' => 'demo']);
            });
        });
    }
}
PHPEOF

cat > "$TARGET/app/service.php" <<'PHPEOF'
<?php
// ThinkPHP 的服务注册入口。
//
// ⚠️ 顺序有讲究：`ThinkService` 必须在前面 ——
// 它的 register() 会绑定 Aimanong 的框架运行时与驱动，
// 后面的 App 服务才能用 `Aimanong::registry()`。
return [
    \Aimanong\ThinkPHP\ThinkService::class,
    \app\AimanongDemoService::class,
];
PHPEOF

cat > "$TARGET/app/provider.php" <<'PHPEOF'
<?php
return [];
PHPEOF

cat > "$TARGET/app/Customer.php" <<'PHPEOF'
<?php
namespace app;

class Customer extends \think\Model
{
    protected $name = 'customers';
    protected $type = ['credit_days' => 'integer'];

    public function getLevelLabelAttr($value)
    {
        return 'L-'.$value;
    }
}
PHPEOF

cat > "$TARGET/app/Agent.php" <<'PHPEOF'
<?php
namespace app;

use Aimanong\Contracts\Permissible;

/**
 * 代理商（代理商后台的登录用户）。
 *
 * 实现 Permissible —— 多应用下每个后台有自己的用户模型，
 * 权限判定基于**能力**而不是具体类（硬判 Administrator 会让
 * 其它后台的用户被判成「未登录」）。
 */
class Agent extends \think\Model implements Permissible
{
    protected $name = 'agents';
    protected $type = ['enabled' => 'integer'];
    protected $hidden = ['password'];

    public function roles(): \think\model\relation\BelongsToMany
    {
        return $this->belongsToMany(\Aimanong\ThinkPHP\Models\Role::class, 'admin_role_user', 'user_id', 'role_id')
            ->wherePivot('user_type', static::class);
    }

    public function isSuper(): bool
    {
        foreach ($this->roles as $role) {
            if ($role->isSuper()) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                if ((string) $permission->getAttr('slug') === $slug) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getAttr('enabled');
    }
}
PHPEOF

# 树形资源 —— 只声明 tree()，不做手工缩进。
# e2e 用它断言「框架自己能渲染层级」（曾经是半成品：_depth 算了但模板没用）。
cat > "$TARGET/app/Department.php" <<'PHPEOF'
<?php

namespace app;

use think\Model;

class Department extends Model
{
    protected $name = 'departments';
    protected $pk = 'id';

    /** 上级部门 —— 外键下拉的候选来源 */
    public function parent()
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }
}
PHPEOF

mkdir -p "$TARGET/app/Aimanong/Actions"

cat > "$TARGET/app/ShipOrder.php" <<'PHPEOF'
<?php

namespace app;

use think\Model;

class ShipOrder extends Model
{
    protected $name = 'ship_orders';
    protected $pk = 'id';
}
PHPEOF

cat > "$TARGET/app/Aimanong/Actions/ShipOrders.php" <<'PHPEOF'
<?php

namespace app\Aimanong\Actions;

use Aimanong\Contracts\BatchActionHandler;
use app\ShipOrder;

/**
 * 自定义批量动作的处理器（ThinkPHP 版）—— 与 Laravel 侧同一套契约。
 */
class ShipOrders implements BatchActionHandler
{
    public function handle(string $model, array $ids): int
    {
        return ShipOrder::whereIn('id', $ids)->update(['status' => 'shipped']);
    }
}
PHPEOF

cat > "$TARGET/app/ShipOrderResource.php" <<'PHPEOF'
<?php

namespace app;

use Aimanong\Resource;

/**
 * 订单 —— 带一个**声明式**自定义批量动作（跨框架同一种写法）。
 */
class ShipOrderResource extends Resource
{
    public static function model(): string
    {
        return ShipOrder::class;
    }

    public static function label(): string
    {
        return '发货单';
    }

    public static function uri(): string
    {
        return 'ship-orders';
    }

    public static function grid(\Aimanong\Grid\Grid $grid): void
    {
        $grid->column('id', 'ID');
        $grid->column('order_no', '订单号');
        $grid->column('amount', '金额');
        $grid->column('status', '状态');
        $grid->batchAction('ship', '标记为已发货')->handler(\app\Aimanong\Actions\ShipOrders::class);
        $grid->batchActions(['delete']);
    }

    public static function form(\Aimanong\Form\Form $form): void
    {
        $form->text('order_no', '订单号')->required();
        $form->number('amount', '金额');
    }
}
PHPEOF

cat > "$TARGET/app/DepartmentResource.php" <<'PHPEOF'
<?php

namespace app;

use Aimanong\Resource;
use Aimanong\Tree\Tree;

class DepartmentResource extends Resource
{
    public static function model(): string
    {
        return Department::class;
    }

    public static function label(): string
    {
        return '部门';
    }

    public static function uri(): string
    {
        return 'departments';
    }

    public static function tree(Tree $tree): void
    {
        $tree->parentColumn('parent_id')->titleColumn('name')->orderColumn('sort');
    }

    public static function grid(\Aimanong\Grid\Grid $grid): void
    {
        $grid->column('id', 'ID')->sortable();   // 树形页也能按 id 排（e2e 用它验证「已声明的列放行」）
        $grid->column('name', '部门名称');
        // 关联列 —— 树形页也要能取到值（框架会预加载 /tree 的关联）
        $grid->column('parent.name', '上级部门');
        $grid->column('sort', '排序');
    }

    public static function form(\Aimanong\Form\Form $form): void
    {
        /*
         * `->max(20)` 是**非 required 规则** —— e2e 用它验证
         * 「除 required 外其它规则真的生效」。
         *
         * 为什么专门为它留一条：ThinkPHP 的 think-validate v3 构造函数无参，
         * 驱动曾写成 `new Validate($rules, $messages)` → 规则集为空 →
         * 除手写的 required 外**所有规则静默失效**。这条断言就是为了让它复发时立刻红。
         */
        $form->text('name', '部门名称')->required()->max(20);
        // 外键下拉：候选自动来自 parent 关联（跨框架同一种写法）
        $form->belongsTo('parent_id', '上级部门');
    }
}
PHPEOF

# ThinkPHP 内置服务器的 router。
#
# ⚠️ 没有它时，**带扩展名的路径不会回退到 index.php** ——
# PHP 内置服务器对 `/admin/assets/x.css` 这类请求直接返回它自己的 404，
# 请求根本到不了框架。表现为「资产路由配了但全 404」，
# 而 `/admin/customers`（无扩展名）却正常，极易误判成框架 bug。
#
# 官方骨架自带这个文件（`php think run` 就靠它）；我们的 demo 要自己生成。
cat > "$TARGET/public/router.php" <<'PHPEOF'
<?php
// 已存在的静态文件交给内置服务器直接返回
if (is_file($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/index.php';

require __DIR__.'/index.php';
PHPEOF

cat > "$TARGET/config/app.php" <<'PHPEOF'
<?php
return ['app_debug' => true, 'default_app' => 'index'];
PHPEOF

cat > "$TARGET/config/cache.php" <<'PHPEOF'
<?php
return [
    'default' => 'file',
    'stores'  => [
        'file' => ['type' => 'File', 'path' => runtime_path().'cache', 'prefix' => '', 'expire' => 0],
    ],
];
PHPEOF

cat > "$TARGET/config/database.php" <<'PHPEOF'
<?php
return [
    'default'        => env('DB_DRIVER', 'sqlite'),
    'auto_timestamp' => true,
    'connections'    => [
        'sqlite' => [
            'type'          => 'sqlite',
            'database'      => runtime_path().'aimanong-e2e.sqlite',
            'prefix'        => '',
            'fields_strict' => true,
            'trigger_sql'   => false,
            'fields_cache'  => false,
        ],
    ],
];
PHPEOF

cat > "$TARGET/config/aimanong.php" <<'PHPEOF'
<?php
return [
    // 后台 URL 前缀（ThinkRuntime::urlPrefix 读它）
    'prefix' => '/admin',

    // 登录限流（防暴力破解）—— 演示里收紧一点，便于 e2e 验证
    'auth' => [
        'model' => '',   // 用默认的 Administrator
        'throttle' => ['enable' => true, 'per_ip' => 20, 'per_account' => 5, 'window' => 60],

        // 演示里打开 RBAC —— e2e 要验证超管绕过与普通角色的 403
        'rbac' => true,
    ],

    /*
     * 多应用（多后台）。
     *
     * 与 Laravel 侧**同一套结构**（title / route.prefix / auth.guard /
     * auth.model），同一个项目的配置可以对照着看。
     *
     * ⚠️ 顺序即优先级：第一个是「无请求上下文时」的默认应用
     * （CLI / 队列拿不到请求路径）。
     */
    'applications' => [
        'admin' => [
            'title' => '总管理后台',
            'route' => ['prefix' => 'admin'],
            'auth'  => ['guard' => 'admin', 'model' => \Aimanong\ThinkPHP\Models\Administrator::class],
        ],
        'agent' => [
            'title' => '代理商后台',
            'route' => ['prefix' => 'agent'],
            'auth'  => ['guard' => 'agent', 'model' => \app\Agent::class],
        ],
    ],

    // 数据字典（配置声明式，与 Laravel 侧同一份格式）
    'dict' => [
        'declarations' => [
            'priority' => [
                'name'  => '优先级',
                'items' => ['low' => '低', 'high' => '高'],
            ],
        ],
    ],

    // 权限节点（配置驱动，默认拒绝）
    //
    // 这是 ThinkPermissionChecker 的最小形态：列出来的即视为拥有。
    // 完整的 RBAC（角色 / 用户绑定 / 数据库）留到后续增量 ——
    // 现在这样至少**不谎报**：没配的节点就是 403。
    /*
     * 扩展登记 —— 必须显式写，框架不自动发现。
     * 演示扩展用来验证扩展机制在 TP 上真的能跑（见 e2e）。
     */
    'extensions' => [
        \app\Extensions\DemoExtension::class,
    ],

    'permissions' => [
        'customers.index', 'customers.show',
        'customers.create', 'customers.update', 'customers.destroy',
        'readonly-customers.index', 'readonly-customers.create',
        'readonly-customers.update', 'readonly-customers.destroy',
    ],
];
PHPEOF

printf "  安装依赖（topthink/framework + aimanong/thinkphp）...\n"
composer install --no-interaction --prefer-dist --no-progress -d "$TARGET" > /tmp/tp-demo-install.log 2>&1 || {
    printf "${RED}❌ composer install 失败${NC}\n"; tail -20 /tmp/tp-demo-install.log; exit 1;
}

# 建库
php -r '
$db = $argv[1];
@unlink($db);
$pdo = new PDO("sqlite:".$db);
$pdo->exec("CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, industry TEXT, level TEXT, credit_days INTEGER, create_time TEXT)");
$pdo->exec("INSERT INTO customers VALUES (1,\"深圳宏远精密\",\"制造业\",\"A\",30,\"2026-10-12 10:00:00\"),(2,\"杭州云图数据\",\"服务业\",\"B\",15,\"2026-10-12 11:00:00\")");
// 代理商后台的登录用户（多应用验证用）
// 树形资源的数据（e2e 要断言层级渲染）
$pdo->exec("CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER, sort INTEGER)");
$pdo->exec("INSERT INTO departments VALUES (1,\"总部\",NULL,1),(2,\"研发部\",1,2),(3,\"前端组\",2,3),(4,\"市场部\",1,4)");
// 自定义批量动作的夹具（与 Laravel 侧同名同结构）
$pdo->exec("CREATE TABLE ship_orders (id INTEGER PRIMARY KEY, order_no TEXT, amount REAL, status TEXT)");
$pdo->exec("INSERT INTO ship_orders VALUES (1,\"SO-001\",100.5,\"paid\"),(2,\"SO-002\",20.0,\"pending\")");
$pdo->exec("CREATE TABLE agents (id INTEGER PRIMARY KEY, username TEXT UNIQUE, name TEXT, password TEXT, company TEXT, enabled INTEGER DEFAULT 1)");
$pdo->exec("INSERT INTO agents VALUES (1,\"agent1\",\"代理A\",\"".password_hash("agent123", PASSWORD_BCRYPT)."\",\"华北代理\",1)");
' "$TARGET/runtime/aimanong-e2e.sqlite"

# 框架数据表与管理员交给 install 命令建 —— 走真实路径，不用另写一套 SQL
php "$TARGET/think" aimanong:install --dir="$TARGET" --admin-user=admin --admin-password=admin123 >/dev/null 2>&1 || true

# 再建一个「普通角色」用户 —— e2e 要用它验证 RBAC 真的会拒
# （只有超管能过的话，「权限生效」是验不出来的）
php -r '
require $argv[1]."/vendor/autoload.php";
(new think\App())->initialize();
use think\facade\Db;
$rm = Aimanong\ThinkPHP\Models\Role::class;
$um = Aimanong\ThinkPHP\Models\Administrator::class;
$role = $rm::where("slug","viewer")->find();
if (! $role) { $role = new $rm; $role->save(["slug"=>"viewer","name"=>"只读角色","is_super"=>0]); }
$user = $um::where("username","viewer")->find();
if (! $user) { $user = new $um; $user->save(["username"=>"viewer","name"=>"只读用户","password"=>$um::hashPassword("viewer123"),"enabled"=>1]); }
Db::name("admin_role_user")->where("user_id",$user->getKey())->delete();
// ⚠️ **必须写 user_type**。
//
// `admin_role_user` 是「按用户模型隔离」的（同一个 user_id 在不同后台可以
// 是不同的人）。模型的 roles() 用 wherePivot("user_type", static::class) 读，
// 这里漏写就会插进一行 user_type="" —— 角色看起来绑上了，实际读不出来。
//
// 实测代价：e2e 那条「普通角色被拒」**一直是假绿** —— 它被拒不是因为权限
// 生效，而是因为角色压根没绑上。直到要给这个账号**开**权限（验按钮级权限）
// 时才发现「怎么开都没用」。
Db::name("admin_role_user")->insert([
    "role_id" => $role->getKey(),
    "user_id" => $user->getKey(),
    "user_type" => $um,
]);
' "$TARGET" >/dev/null 2>&1 || true

# ── 同步权限节点 ──
#
# ⚠️ 必须在 Resource 都建好**之后**跑。ThinkPHP 驱动此前**根本没有**
#    这个入口，于是 RBAC 实际不可用：节点永远建不出来，除超管外
#    所有人访问任何页面都是 403 —— 而 e2e 断言的是「普通角色被拒」，
#    「权限生效」与「谁都没权限」在测试里结果完全一样，所以一直没暴露。
echo "  → 同步权限节点"
php "$TARGET/think" aimanong:permission sync >/dev/null 2>&1 || true

# 给普通角色开「只读」权限 —— 否则「按钮级权限」验不出来
# （没有 index 权限时页面直接 403，连列表都看不到）
php -r '
require $argv[1]."/vendor/autoload.php";
(new think\App())->initialize();
use think\facade\Db;
$role = Aimanong\ThinkPHP\Models\Role::where("slug","viewer")->find();
$perm = Aimanong\ThinkPHP\Models\Permission::where("slug","admin.departments.index")->find();
if ($role && $perm) {
    Db::name("admin_permission_role")->where("role_id",$role->getKey())->delete();
    Db::name("admin_permission_role")->insert(["role_id"=>$role->getKey(),"permission_id"=>$perm->getKey()]);
}
' "$TARGET" >/dev/null 2>&1 || true

# ⚠️ 关键断言：装了 ThinkPHP 驱动**不能**把 illuminate 拖进来。
#    PoC 阶段实测过：illuminate/support 会抢注全局 env()，
#    导致 TP 启动即致命（Class "PhpOption\Option" not found）。
if [ -d "$TARGET/vendor/illuminate" ]; then
    printf "${RED}❌ vendor/illuminate 存在 —— ThinkPHP 项目里混进了 Laravel 组件${NC}\n"
    printf "   这正是拆包要消灭的冲突（全局 env() 被抢注 → TP 启动致命）\n"
    exit 1
fi
printf "  ${GREEN}✅${NC} 零 illuminate\n"

printf "${GREEN}✅ 演示项目就绪${NC} → %s\n" "$TARGET"
