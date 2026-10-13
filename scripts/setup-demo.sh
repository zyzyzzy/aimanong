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

# ── 权限节点（内置 Resource 的节点要写库）──
echo "  → 同步权限节点"
php artisan aimanong:permission sync >/dev/null 2>&1 || true

# ── 登记 region 扩展 ──
#
# ⚠️ 2026-10-13 修：插件的扩展**必须显式列进** `config('aimanong.extensions')`，
# 框架不会自动发现。
#
# region 包的 composer.json 里写了 `extra.aimanong.extension`，
# 但**全仓库没有任何代码读它**（grep 过），InstallCommand 也不扫 composer 元数据。
# 也就是说那是一段死元数据。
#
# 后果（实测）：全新项目 `composer require aimanong/region` 之后，
# RegionExtension 根本不会 boot —— `FieldType::fieldViewFor('region')` 是 null，
# 省市区字段退化成普通文本框。而 e2e 里那两条断言就是在测这个。
#
# demo 的职责是**真的把插件跑起来**，所以这里显式登记。
# （插件自动发现是独立的产品决策，见拆包计划「本段遗留」。）
echo "  → 登记 region 扩展"
php -r '
$f = "config/aimanong.php";
if (! is_file($f)) { exit(0); }
$s = (string) file_get_contents($f);
if (str_contains($s, "RegionExtension::class")) { exit(0); }
$needle = "\x27extensions\x27 => [";
$pos = strpos($s, $needle);
if ($pos === false) {
    fwrite(STDERR, "  ⚠️  配置里找不到 extensions 键，region 扩展未登记\n");
    exit(1);
}
$pos += strlen($needle);
$s = substr($s, 0, $pos)
    . "\n        \\Aimanong\\Region\\RegionExtension::class,"
    . substr($s, $pos);
file_put_contents($f, $s);
'

echo
echo "✅ demo 项目就绪: $DEMO"
echo "   启动服务: cd $DEMO && php artisan serve --port=8899"
