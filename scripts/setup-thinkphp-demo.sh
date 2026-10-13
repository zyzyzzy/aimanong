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

cat > "$TARGET/app/service.php" <<'PHPEOF'
<?php
// ThinkPHP 的服务注册入口 —— Aimanong 的驱动通过它挂上
return [
    \Aimanong\ThinkPHP\ThinkService::class,
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
    'permissions' => ['customers.index'],
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
' "$TARGET/runtime/aimanong-e2e.sqlite"

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
