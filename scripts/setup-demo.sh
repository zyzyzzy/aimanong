#!/usr/bin/env bash
#
# 从零搭一个能跑端到端测试的 demo 项目。
#
# ## 用途
#
# 1. CI 的 e2e job（每次 push 都验证「新人装框架能否跑通」）
# 2. 本地想复现 e2e 时
#
# ## 为什么值得单独写一个脚本
#
# 端到端测试需要「一个装了框架、有真实数据、服务在跑」的项目。
# 此前这件事靠人工维护一个仓库外的 demo-app —— 结果是：
# **CI 根本没法跑 e2e**，测试与框架长期脱节。
#
# 副作用（好的那种）：这个脚本顺带证明了框架的安装路径是通的。
#
# 用法：
#   bash scripts/setup-demo.sh [目标目录]
#     默认目标：<repo>/../demo-app
#
#   AMN_MINIMAL=1 bash scripts/setup-demo.sh /tmp/x
#     最小安装：只装 framework，不装任何可选插件 ——
#     用来验证「新人照 README 只装核心包」时后台仍能打开

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEMO="${1:-${DEMO_PATH:-$ROOT/../demo-app}}"

echo "═══ 搭建 demo 项目 ═══"
echo "  目标: $DEMO"

if [ -d "$DEMO" ] && [ -f "$DEMO/artisan" ]; then
  echo "  ✓ 已存在，跳过创建"
else
  echo "  → 创建 Laravel 项目…"

  # ⚠️ 必须锁定骨架版本。
  #
  # 不锁的话拿到的是**最新** laravel/laravel 骨架 ——
  # 而框架的依赖链（laravel/mcp ^0.4）目前只支持 Laravel 12：
  #
  #   - laravel/mcp v0.4.2 requires illuminate/container ^12.41.1
  #   - 骨架若给到 Laravel 13 → 依赖无解 → composer 报
  #     "Your requirements could not be resolved"
  #
  # 本地不容易发现（composer 缓存常给出旧版），CI 干净环境必踩。
  # 这正是 e2e job 从加进去就红着的原因。
  composer create-project "laravel/laravel:^12.0" "$DEMO" \
    --no-interaction --prefer-dist --quiet
fi

cd "$DEMO"

# ── 用 path 仓库指向本地框架（CI 与本地都适用）──
echo "  → 接入本地框架包"
composer config repositories.aimanong path "$ROOT/packages/framework" --no-interaction
composer config repositories.aimanong-core path "$ROOT/packages/core" --no-interaction
composer config repositories.aimanong-laravel path "$ROOT/packages/laravel" --no-interaction
composer config repositories.aimanong-region path "$ROOT/packages/region" --no-interaction

# --minimal：只装 framework，不装任何可选插件。
# 用来验证「新人照 README 只装核心包」时后台仍能打开 ——
# 曾经因为模板硬依赖 region，这种项目一打开列表页就 Fatal error。
if [ "${1:-}" = "--minimal" ] || [ "${AMN_MINIMAL:-0}" = "1" ]; then
  echo "  → 【最小安装】只装 aimanong/framework，不装插件"
  composer require aimanong/framework:@dev --no-interaction --quiet 2>/dev/null || \
    composer require aimanong/framework:@dev --no-interaction
else
  composer require aimanong/framework:@dev aimanong/region:@dev \
    --no-interaction --quiet 2>/dev/null || \
    composer require aimanong/framework:@dev aimanong/region:@dev --no-interaction
fi

# ── SQLite ──
echo "  → 配置 SQLite"
touch database/database.sqlite
sed -i.bak 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env 2>/dev/null || true
grep -q '^DB_CONNECTION=sqlite' .env || echo 'DB_CONNECTION=sqlite' >> .env
grep -q '^AIMANONG_RBAC=' .env || echo 'AIMANONG_RBAC=true' >> .env
rm -f .env.bak

# ── 安装框架（发布配置 + 迁移 + 管理员账号）──
#
# ⚠️ 2026-10-13 修：管理员账号必须在**这一次**就带上固定密码。
#
# 原实现分两步：先 `aimanong:install` 建一个**随机密码**的管理员，
# 再跑一次 `aimanong:install --force --admin-user=admin --admin-password=admin123`
# 想覆盖它。但第二次的实际输出是：
#
#     ✓ 已存在管理员账号，跳过创建（不会重置已有密码）
#
# 也就是说密码从来没被设成 admin123。而这一步和下面的权限同步都写着
# `|| true`，失败被吞掉 —— 于是 e2e 用 admin/admin123 登录必然 302。
#
# 实测代价：CI 的 http-e2e 长期只有 9/24 通过，却因为
# pre-release.sh 只看退出码而被显示成「✅ http-e2e 通过」。
# （退出码问题已在本次一并修复，所以现在它才会如实报红。）
echo "  → 安装框架"
php artisan aimanong:install --admin-user=admin --admin-password=admin123 \
    --no-interaction 2>/dev/null \
  || php artisan aimanong:install --force --admin-user=admin --admin-password=admin123 \
    --no-interaction

# ── 保证 demo 管理员密码 ──
#
# ⚠️ 上面的 install 只在「账号不存在」时才建。**重跑**这个脚本时账号已存在，
# install 会（正确地）跳过，密码保持上一次的值 —— 而 e2e 需要确定的 admin123。
#
# `aimanong:install` 不重置已有密码是**对的安全行为**（不能因为重跑安装就把
# 生产管理员密码改掉）。所以这里由 demo 脚本自己显式保证，而不是让 install 破例。
echo "  → 固定 demo 管理员密码"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cls  = \Aimanong\Models\Administrator::class;
$user = $cls::where("username", "admin")->first();
if (! $user) {
    fwrite(STDERR, "  ⚠️  管理员账号不存在，e2e 会失败\n");
    exit(1);
}
$user->password = \Illuminate\Support\Facades\Hash::make("admin123");
$user->save();
' 2>&1 | tail -2

# ── 树形资源（e2e 要断言「框架自己能渲染层级」）──
#
# ⚠️ 这段是**因为 CI 红了才补的**：本地 demo 里的 DepartmentResource 是早先
# 手工建的，而 CI 的 demo 由本脚本从零重建 —— 于是 e2e 断言树形时 404。
# 又一次「本地有、CI 没有」：凡 e2e 依赖的东西，必须在建库脚本里。
echo "  → 建树形资源（departments）"
mkdir -p app/Aimanong
php -r '
$db = "database/database.sqlite";
$pdo = new PDO("sqlite:".$db);
$pdo->exec("DROP TABLE IF EXISTS departments");
$pdo->exec("CREATE TABLE departments (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER, sort INTEGER)");
$pdo->exec("INSERT INTO departments VALUES (1,\"总部\",NULL,1),(2,\"研发部\",1,2),(3,\"前端组\",2,3),(4,\"市场部\",1,4)");
$pdo->exec("DROP TABLE IF EXISTS ship_orders");
$pdo->exec("CREATE TABLE ship_orders (id INTEGER PRIMARY KEY, order_no TEXT, amount REAL, status TEXT)");
$pdo->exec("INSERT INTO ship_orders VALUES (1,\"SO-001\",100.5,\"paid\"),(2,\"SO-002\",20.0,\"pending\")");
echo "     departments + ship_orders 表就绪\n";
'
cat > app/Models/Department.php <<'PHPEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $table = 'departments';
    public $timestamps = false;
    protected $guarded = [];

    /** 上级部门 —— 外键下拉的候选来源 */
    public function parent()
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }
}
PHPEOF
mkdir -p app/Aimanong/Actions

cat > app/Aimanong/Actions/ShipOrders.php <<'PHPEOF'
<?php

namespace App\Aimanong\Actions;

use Aimanong\Contracts\BatchActionHandler;
use App\Models\ShipOrder;

/**
 * 自定义批量动作的处理器 —— 只写业务。
 *
 * 权限（`orders.ship` 节点）、id 校验、操作日志都由框架在调用它之前处理掉。
 */
class ShipOrders implements BatchActionHandler
{
    public function handle(string $model, array $ids): int
    {
        return ShipOrder::whereIn('id', $ids)->update(['status' => 'shipped']);
    }
}
PHPEOF

cat > app/Models/ShipOrder.php <<'PHPEOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipOrder extends Model
{
    protected $table = 'ship_orders';
    public $timestamps = false;
    protected $guarded = [];
}
PHPEOF

cat > app/Aimanong/ShipOrderResource.php <<'PHPEOF'
<?php

namespace App\Aimanong;

use Aimanong\Resource;
use App\Aimanong\Actions\ShipOrders;
use App\Models\ShipOrder;

/**
 * 订单 —— 带一个**声明式**自定义批量动作。
 *
 * 声明里只有数据（动作名/标签/处理器类名），行为在处理器里（ADR-003）。
 */
class ShipOrderResource extends Resource
{
    public static function model(): string { return ShipOrder::class; }
    public static function label(): string { return '订单'; }
    public static function uri(): string { return 'ship-orders'; }

    public static function grid(\Aimanong\Grid\Grid $grid): void
    {
        $grid->column('id', 'ID');
        $grid->column('order_no', '订单号');
        $grid->column('amount', '金额');
        $grid->column('status', '状态');
        $grid->batchAction('ship', '标记为已发货')->handler(ShipOrders::class);
        $grid->batchActions(['delete']);
    }

    public static function form(\Aimanong\Form\Form $form): void
    {
        $form->text('order_no', '订单号')->required();
        $form->number('amount', '金额');
        $form->select('status', '状态')->options(['paid' => '已付款', 'pending' => '待付款', 'shipped' => '已发货']);
    }
}
PHPEOF
mkdir -p app/Aimanong/Actions

cat > app/Aimanong/DepartmentResource.php <<'PHPEOF'
<?php

namespace App\Aimanong;

use Aimanong\Resource;
use Aimanong\Tree\Tree;
use App\Models\Department;

/**
 * 树形资源 —— 只声明 tree()，不做任何手工缩进。
 *
 * e2e 用它断言「层级由框架渲染」（曾经是半成品：
 * flattenTree 算了 _depth、CSS 也写好了，就是模板没用）。
 */
class DepartmentResource extends Resource
{
    public static function model(): string { return Department::class; }
    public static function label(): string { return '部门'; }
    public static function uri(): string { return 'departments'; }

    public static function tree(Tree $tree): void
    {
        $tree->parentColumn('parent_id')->titleColumn('name')->orderColumn('sort');
    }

    public static function grid(\Aimanong\Grid\Grid $grid): void
    {
        $grid->column('id', 'ID');
        $grid->column('name', '部门名称');
        // 关联列 —— 树形页也要能取到值（框架会预加载 /tree 的关联）
        $grid->column('parent.name', '上级部门');
        $grid->column('sort', '排序');
    }

    public static function form(\Aimanong\Form\Form $form): void
    {
        $form->text('name', '部门名称')->required();
        // 外键下拉：候选自动来自 parent 关联（跨框架同一种写法）
        $form->belongsTo('parent_id', '上级部门');
    }
}
PHPEOF
# ⚠️ 注册必须写进 AppServiceProvider —— 用 `php -r` 调一次 registry() 是
#    **一次性进程**，请求进来时根本不存在（第一版就是这么写错的）。
php -r '
$f = "app/Providers/AppServiceProvider.php";
$s = file_get_contents($f);

/*
 * 幂等守卫要检**全部**要注册的类，不能只看第一个 ——
 * 只看 DepartmentResource 的话，后加的 ShipOrderResource 永远进不来
 * （重跑脚本时被守卫提前 exit）。实测踩到。
 */
$needed = ["DepartmentResource", "ShipOrderResource"];
$missing = array_values(array_filter($needed, fn ($c) => ! str_contains($s, $c)));

if ($missing === []) {
    echo "     两个 Resource 都已在 Provider 里\n";
    exit(0);
}

/*
 * 补 use —— **逐个**检查，不能只看第一个。
 *
 * ⚠️ 踩过：只判 `use Aimanong\\Aimanong;` 在不在，而它一直在，
 * 于是后加的 ShipOrderResource 的 use 永远写不进去。
 * 而 `register(ShipOrderResource::class)` 会解析成
 * `App\\Providers\\ShipOrderResource`（不存在）—— **静默失败**：
 * provider 文件里明明有那一行，注册表里没有，页面 404，极难定位。
 */
foreach (["use Aimanong\\Aimanong;", "use App\\Aimanong\\DepartmentResource;", "use App\\Aimanong\\ShipOrderResource;"] as $useLine) {
    if (! str_contains($s, $useLine)) {
        $s = preg_replace("/^use /m", $useLine."\nuse ", $s, 1);
    }
}

// 把注册写进 register() —— 已注册的不重复插（重跑脚本否则插出重复行）
$body = "";
foreach (["DepartmentResource", "ShipOrderResource"] as $cls) {
    if (! str_contains($s, "register({$cls}::class)")) {
        $body .= "\n        Aimanong::registry()->register({$cls}::class);";
    }
}

if ($body !== "" && preg_match("/(public function register\\(\\): void\\s*\\{)/", $s)) {
    $s = preg_replace("/(public function register\\(\\): void\\s*\\{)/", "$1".$body, $s, 1);
} elseif ($body !== "") {
    fwrite(STDERR, "     ⚠️ 没找到 register()，需人工处理\n");
    exit(1);
}
file_put_contents($f, $s);
'

# ── 多应用（三后台）──
#
# ⚠️ 必须在 demo 里真的配出多应用，否则 e2e 的多应用隔离断言
# 只能「跳过」—— 那等于 CI 永远测不到多应用（本项目吃过的亏）。
echo "  → 配置多应用（admin / user / agent）"
php "$ROOT/scripts/setup-demo-multiapp.php" 2>&1 | sed 's/^/  /' || {
    echo "  ⚠️  多应用配置失败（e2e 会跳过相关断言）"
}

# ── 登记 region 扩展 ──
#
# ⚠️ 扩展**必须显式登记**，框架不自动发现（见 region/README）。
#
# ⚠️ 而且必须放在 `aimanong:install` **之后**：install 会重新发布
# config/aimanong.php，把之前手工加的 extensions 覆盖掉。
# 实测：重跑本脚本后 region 字段全部编译失败
# （`Form 上不存在方法 region()`），7 个 Resource 红。
# ── 数据字典声明（演示用的场景资源要靠它）──
#
# ⚠️ 两个坑，都实测踩过：
#
# ① 字典是**代码声明**（config 里的 foundation.dict.declarations），不是种子数据。
#    而 `aimanong:install` 会重新发布 config/aimanong.php —— 手工加过的声明被覆盖，
#    引用这些字典的 Resource 就全部 `DICT_NOT_FOUND` 编译失败，
#    **连带 e2e 的「可读 Resource」断言一起红**。
# ② 位置必须是 `foundation.dict.declarations`。加到顶层 `dict` 不报错，
#    但框架读不到 —— 静默无效。
echo "  → 声明演示用数据字典"
php -r '
$f = "config/aimanong.php";
$s = file_get_contents($f);

if (str_contains($s, "crm_contract_status")) {
    echo "     字典已声明\n";
    exit(0);
}

$decls = <<<'"'"'PHP'"'"'
[
                '"'"'priority'"'"' => [
                    '"'"'name'"'"' => '"'"'优先级'"'"',
                    '"'"'items'"'"' => ['"'"'low'"'"' => '"'"'低'"'"', '"'"'normal'"'"' => '"'"'普通'"'"', '"'"'high'"'"' => '"'"'高'"'"', '"'"'urgent'"'"' => '"'"'紧急'"'"'],
                ],
                '"'"'article_status'"'"' => [
                    '"'"'name'"'"' => '"'"'文章状态'"'"',
                    '"'"'items'"'"' => ['"'"'draft'"'"' => '"'"'草稿'"'"', '"'"'pending'"'"' => '"'"'待审'"'"', '"'"'published'"'"' => '"'"'已发布'"'"', '"'"'archived'"'"' => '"'"'已归档'"'"'],
                ],
                '"'"'crm_level'"'"' => [
                    '"'"'name'"'"' => '"'"'客户等级'"'"',
                    '"'"'items'"'"' => ['"'"'A'"'"' => '"'"'A 级（重点）'"'"', '"'"'B'"'"' => '"'"'B 级（常规）'"'"', '"'"'C'"'"' => '"'"'C 级（观察）'"'"'],
                ],
                '"'"'crm_stage'"'"' => [
                    '"'"'name'"'"' => '"'"'商机阶段'"'"',
                    '"'"'items'"'"' => ['"'"'lead'"'"' => '"'"'线索'"'"', '"'"'qualified'"'"' => '"'"'已确认'"'"', '"'"'proposal'"'"' => '"'"'方案报价'"'"', '"'"'negotiation'"'"' => '"'"'商务谈判'"'"', '"'"'won'"'"' => '"'"'赢单'"'"', '"'"'lost'"'"' => '"'"'输单'"'"'],
                ],
                '"'"'crm_followup_type'"'"' => [
                    '"'"'name'"'"' => '"'"'跟进方式'"'"',
                    '"'"'items'"'"' => ['"'"'call'"'"' => '"'"'电话'"'"', '"'"'visit'"'"' => '"'"'拜访'"'"', '"'"'meeting'"'"' => '"'"'会议'"'"', '"'"'email'"'"' => '"'"'邮件'"'"', '"'"'wechat'"'"' => '"'"'微信'"'"'],
                ],
                '"'"'crm_source'"'"' => [
                    '"'"'name'"'"' => '"'"'客户来源'"'"',
                    '"'"'items'"'"' => ['"'"'web'"'"' => '"'"'官网'"'"', '"'"'referral'"'"' => '"'"'转介绍'"'"', '"'"'exhibition'"'"' => '"'"'展会'"'"', '"'"'cold'"'"' => '"'"'陌生拜访'"'"', '"'"'ad'"'"' => '"'"'广告投放'"'"'],
                ],
                '"'"'crm_customer_status'"'"' => [
                    '"'"'name'"'"' => '"'"'客户状态'"'"',
                    '"'"'items'"'"' => ['"'"'potential'"'"' => '"'"'潜在'"'"', '"'"'following'"'"' => '"'"'跟进中'"'"', '"'"'signed'"'"' => '"'"'已签约'"'"', '"'"'lost'"'"' => '"'"'已流失'"'"'],
                ],
                '"'"'crm_industry'"'"' => [
                    '"'"'name'"'"' => '"'"'所属行业'"'"',
                    '"'"'items'"'"' => ['"'"'manufacturing'"'"' => '"'"'制造业'"'"', '"'"'internet'"'"' => '"'"'互联网'"'"', '"'"'finance'"'"' => '"'"'金融'"'"', '"'"'education'"'"' => '"'"'教育'"'"', '"'"'medical'"'"' => '"'"'医疗'"'"', '"'"'retail'"'"' => '"'"'零售'"'"', '"'"'other'"'"' => '"'"'其他'"'"'],
                ],
                '"'"'crm_contract_status'"'"' => [
                    '"'"'name'"'"' => '"'"'合同状态'"'"',
                    '"'"'items'"'"' => ['"'"'draft'"'"' => '"'"'草稿'"'"', '"'"'reviewing'"'"' => '"'"'审核中'"'"', '"'"'signed'"'"' => '"'"'已签署'"'"', '"'"'executing'"'"' => '"'"'执行中'"'"', '"'"'finished'"'"' => '"'"'已完成'"'"', '"'"'terminated'"'"' => '"'"'已终止'"'"'],
                ],
            ]
PHP;

// 只替换 foundation.dict 里那个空的 declarations
$s = preg_replace(
    "/('"'"'declarations'"'"'\s*=>\s*)\[\s*\]/",
    "$1" . $decls,
    $s,
    1
);

if (! str_contains($s, "crm_contract_status")) {
    fwrite(STDERR, "     ⚠️ 没能写入 foundation.dict.declarations\n");
    exit(1);
}

file_put_contents($f, $s);
echo "     6 个字典已声明\n";
' 2>&1 | tail -1

echo "  → 登记 region 扩展"
php -r '
$f = "config/aimanong.php";
$s = file_get_contents($f);

if (str_contains($s, "RegionExtension")) {
    echo "     region 扩展已登记\n";
    exit(0);
}

$s = preg_replace(
    "/^([ \t]*)'"'"'extensions'"'"' => \[\n/m",
    "$1'"'"'extensions'"'"' => [\n$1    \\Aimanong\\Region\\RegionExtension::class,\n",
    $s,
    1
);

if (! str_contains($s, "RegionExtension")) {
    fwrite(STDERR, "     ⚠️ 没能写入 extensions，region 字段会编译失败\n");
    exit(1);
}

file_put_contents($f, $s);
echo "     region 扩展已登记\n";
' 2>&1 | tail -1

# ── 按钮级权限验证账号 ──
#
# 只给 departments.index（**不给** create/update/destroy），
# 用来断言「没有权限的动作按钮服务端根本不渲染」。
# 放在权限同步**之后** —— 节点要先存在才能绑定。
echo "  → 建按钮级权限验证账号（viewer01）"
php artisan aimanong:permission sync >/dev/null 2>&1 || true
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Aimanong\Models\Administrator as Admin;
use Aimanong\Models\Permission;
use Aimanong\Models\Role;
use Illuminate\Support\Facades\Hash;

$role = Role::firstOrNew(["slug" => "dept-viewer"]);
$role->name = "部门查看员";
$role->save();

/*
 * ⚠️ 多应用下权限节点带**应用前缀**（`admin.departments.index`），
 * 不是裸的 `departments.index`。这里按框架自己的 slug() 解析，
 * 不要手写字符串 —— 手写过一次，绑到了裸 slug 上，断言全红。
 */
$slug = \Aimanong\Auth\PermissionGate::appSlug("admin", "departments", "index");
$perm = Permission::where("slug", $slug)->first();
if (! $perm) {
    fwrite(STDERR, "  ⚠️  没有 {$slug} 权限节点，按钮级权限断言会跳过\n");
} else {
    $role->permissions()->sync([$perm->id]);
}

$u = Admin::firstOrNew(["username" => "viewer01"]);
$u->password = Hash::make("viewer123");
$u->name = "只读查看";
$u->enabled = true;
$u->save();
$u->roles()->sync([$role->id]);
echo "     viewer01/viewer123（只有 departments.index）\n";
' 2>&1 | tail -2

echo
echo "✅ demo 项目就绪: $DEMO"
echo "   启动服务: cd $DEMO && php artisan serve --port=8899"
