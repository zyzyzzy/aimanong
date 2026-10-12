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

# ── 安装框架（发布配置 + 迁移）──
echo "  → 安装框架"
php artisan aimanong:install --no-interaction 2>/dev/null || php artisan aimanong:install --force --no-interaction

# ── 管理员账号 ──
#
# ⚠️ 正常情况下**不需要**这一段 —— `aimanong:install` 自己会创建。
#
# 但 e2e 测试要求固定的账号密码（admin/admin123），而安装命令
# 默认生成随机强密码。所以这里显式指定，用非交互参数的正式路径，
# 而不是绕过安装命令手工写库。
echo "  → 创建管理员账号（非交互）"
php artisan aimanong:install --force --admin-user=admin --admin-password=admin123 \
  --no-interaction >/dev/null 2>&1 || true

# ── 权限节点（内置 Resource 的节点要写库）──
echo "  → 同步权限节点"
php artisan aimanong:permission sync >/dev/null 2>&1 || true

echo
echo "✅ demo 项目就绪: $DEMO"
echo "   启动服务: cd $DEMO && php artisan serve --port=8899"
