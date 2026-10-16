#!/usr/bin/env bash
#
# 发布前验证流程（可执行版）
#
# 用法：
#   bash scripts/pre-release.sh           # 跑基础检查 + 端到端
#   bash scripts/pre-release.sh --with-e2e # 含需要 demo 服务的 HTTP 测试
#
# 说明：AI 实测 / 真实场景 / 插件验证需要人工发起（见 internal-docs/发布前验证流程.md），
#       本脚本负责可自动化的部分。

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# ── 拆包后的两个"框架目录" ──
#
#   PRODUCT  产品包（元包）—— 对外版本号在这里，README/CHANGELOG 跟它走
#   FW       驱动包（有 vendor / 测试 / PHPStan）—— 质量门禁在这里跑
#
# 拆包前它们是同一个目录，所以下面所有用到的地方都要显式区分。
PRODUCT="$ROOT/packages/framework"
FW="$ROOT/packages/laravel"
FAIL=0
WITH_E2E=0
SKIP_NET=0

# 参数解析（原来只认位置固定的 $1，加第二个参数就失效）
for arg in "$@"; do
  case "$arg" in
    --with-e2e)     WITH_E2E=1 ;;
    --skip-network) SKIP_NET=1 ;;
  esac
done

# 颜色
# 注意：颜色变量名不要用单字母 N ——
# 本脚本里 N 用作计数变量，会覆盖颜色重置码，
# 导致输出里莫名多出数字（踩过一次）。
if [ -t 1 ]; then
  C_G='\033[0;32m'; C_R='\033[0;31m'; C_Y='\033[0;33m'; C_B='\033[1m'; C_N='\033[0m'
else
  C_G=''; C_R=''; C_Y=''; C_B=''; C_N=''
fi

# 注意：所有输出函数末尾必须 `return 0`。
# 否则函数返回值是 printf 的字符数，导致调用处出现多余的 "0" 输出。
ok()   { printf "  ${C_G}✅${C_N} %s\n" "$1"; return 0; }
bad()  { printf "  ${C_R}❌${C_N} %s\n" "$1"; FAIL=1; return 0; }
warn() { printf "  ${C_Y}⚠️${C_N}  %s\n" "$1"; return 0; }
head1(){ printf "\n${C_B}%s${C_N}\n" "$1"; return 0; }

# 用法: grep_assert "描述" 文件 模式
grep_assert() {
  local desc="$1" file="$2" pattern="$3"
  if [ -f "$file" ] && grep -q "$pattern" "$file" 2>/dev/null; then
    ok "$desc"
  else
    bad "$desc"
  fi
  return 0
}

# 用法: file_assert "路径"
file_assert() {
  if [ -f "$1" ]; then ok "${1#"$ROOT"/}"; else bad "${1#"$ROOT"/}"; fi
  return 0
}

printf "${C_B}"
echo "╔══════════════════════════════════════════════════╗"
echo "║   Aimanong 发布前验证                            ║"
echo "╚══════════════════════════════════════════════════╝"
printf "${C_N}"

# ─────────────────────────────────────────────────
head1 "【1】版本号一致性"

V_COMPOSER=$(python3 -c "import json;print(json.load(open('$PRODUCT/composer.json'))['version'])" 2>/dev/null || echo "?")
V_CODE=$(cd "$FW" && php -r 'require "vendor/autoload.php"; echo Aimanong\Aimanong::version();' 2>/dev/null || echo "?")

if [ "$V_COMPOSER" = "$V_CODE" ] && [ "$V_COMPOSER" != "?" ]; then
  ok "composer.json ($V_COMPOSER) = Aimanong::version() ($V_CODE)"
else
  bad "版本号不一致: composer=$V_COMPOSER code=$V_CODE"
fi

grep_assert "CHANGELOG 记录 $V_COMPOSER" "$ROOT/CHANGELOG.md" "$V_COMPOSER"
grep_assert "README 版本徽章" "$ROOT/README.md" "version-$V_COMPOSER"

# ─────────────────────────────────────────────────
head1 "【2】质量门禁"

cd "$FW" || exit 1

./vendor/bin/phpunit > /tmp/amn-ut.log 2>&1
if ./vendor/bin/phpunit > /tmp/amn-ut.log 2>&1; then
  UT_LINE=$(grep -oE 'OK \([0-9]+ tests, [0-9]+ assertions\)' /tmp/amn-ut.log | head -1)
  ok "PHPUnit ${UT_LINE:-(通过)}"
else
  bad "PHPUnit 失败"
  sed 's/^/      /' /tmp/amn-ut.log | tail -15
fi

if ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/amn-st.log 2>&1; then
  ok "PHPStan Level 8"
else
  bad "PHPStan 有错误"
  sed 's/^/      /' /tmp/amn-st.log | head -15
fi

if ./vendor/bin/pint --test > /tmp/amn-pt.log 2>&1; then
  ok "Pint 代码风格"
else
  bad "Pint 风格不合规"
fi

# 模板产物门禁：ThinkPHP 侧的模板是**生成物**（真源是 Laravel 那套 Blade）。
# 生成物最典型的失效方式就是「源改了、产物忘了重生成」—— 这里重跑一遍
# 转换并比对，不一致就拦。再过一遍硬规则校验（每条都对应一次实测踩过的坑）。
if command -v python3 >/dev/null 2>&1; then
  TMP_TPL=$(mktemp -d)
  if python3 "$ROOT/scripts/blade2think.py" "$ROOT/packages/laravel/resources/views" "$TMP_TPL" >/dev/null 2>&1 \
      && diff -rq "$TMP_TPL" "$ROOT/packages/thinkphp/resources/views" >/dev/null 2>&1; then
    ok "ThinkPHP 模板产物与 Blade 真源同步"
  else
    bad "ThinkPHP 模板产物与 Blade 真源**不同步**"
    echo "       → 跑：python3 scripts/blade2think.py"
  fi
  rm -rf "$TMP_TPL"
else
  warn "没有 python3，跳过模板产物同步检查"
fi

if bash "$ROOT/scripts/check-think-templates.sh" > /tmp/amn-tpl.log 2>&1; then
  N=$(find "$ROOT/packages/thinkphp/resources/views" -name '*.html' | wc -l | tr -d ' ')
  ok "ThinkPHP 模板产物通过全部硬性规则（${N} 个模板）"
else
  bad "ThinkPHP 模板产物违反硬性规则"
  sed 's/^/      /' /tmp/amn-tpl.log | head -20
fi

# ThinkPHP 包的静态分析。
#
# ⚠️ 这一段是**全面验收时才补上的**：`packages/thinkphp` 此前完全没有
# PHPStan / Pint —— 3451 行代码没有过一次静态分析，只靠 e2e 覆盖运行时。
# 补上后立刻抓到几个真问题（详见验收报告）：
#   · `topthink/think-template` **不在包依赖里**（ThinkView 用了它）
#   · `think\Middleware::getGlobal()` 根本不存在（每次 boot 都重复加中间件）
#   · 多处类型提示写错（Query vs BaseQuery）
if [ -x "$ROOT/packages/thinkphp/vendor/bin/phpstan" ]; then
  if (cd "$ROOT/packages/thinkphp" && vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/amn-tp-stan.log 2>&1); then
    ok "ThinkPHP PHPStan Level 8"
  else
    bad "ThinkPHP PHPStan 未通过"
    tail -12 /tmp/amn-tp-stan.log | sed 's/^/      /'
  fi

  if (cd "$ROOT/packages/thinkphp" && vendor/bin/pint --test > /tmp/amn-tp-pint.log 2>&1); then
    ok "ThinkPHP Pint 代码风格"
  else
    bad "ThinkPHP Pint 未通过"
    tail -6 /tmp/amn-tp-pint.log | sed 's/^/      /'
  fi
else
  warn "packages/thinkphp 未安装依赖，跳过其静态分析（CI 会跑）"
fi

#
# 内核 packages/core —— 它是**两个驱动共用的那一半**，
# 但此前 **0 单测 / 0 PHPStan / 0 Pint**。
#
# ⚠️ 注意：laravel 与 thinkphp 的 PHPStan 都把 `../core/src` 加进了 paths，
# 所以它**间接**过了静态分析。但那有两个问题：
#   1. 用的是各自的配置与忽略规则 —— core 自己的问题可能被别人的 ignore 盖住
#   2. 单测完全没有：core 有 99 个类，只有被驱动测试顺带覆盖的那些有保障
#
# core 是**框架无关**的（`check-core-purity.sh` 保证不依赖 illuminate），
# 所以它能独立测试 —— 不需要容器、不需要数据库。
#
if [ -x "$ROOT/packages/core/vendor/bin/phpunit" ]; then
  if (cd "$ROOT/packages/core" && vendor/bin/phpunit > /tmp/amn-co-ut.log 2>&1); then
    CO_LINE=$(grep -oE 'OK \([0-9]+ tests, [0-9]+ assertions\)' /tmp/amn-co-ut.log | head -1)
    ok "core PHPUnit ${CO_LINE:-(通过)}"
  else
    bad "core PHPUnit 失败"
    tail -20 /tmp/amn-co-ut.log | sed 's/^/      /'
  fi
else
  warn "packages/core 未装 phpunit，跳过其单测（CI 会跑）"
fi

if [ -x "$ROOT/packages/core/vendor/bin/phpstan" ]; then
  if (cd "$ROOT/packages/core" && vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/amn-co-stan.log 2>&1); then
    ok "core PHPStan Level 8"
  else
    bad "core PHPStan 未通过"
    tail -12 /tmp/amn-co-stan.log | sed 's/^/      /'
  fi
else
  warn "packages/core 未装 phpstan，跳过其静态分析（CI 会跑）"
fi

if [ -x "$ROOT/packages/core/vendor/bin/pint" ]; then
  if (cd "$ROOT/packages/core" && vendor/bin/pint --test > /tmp/amn-co-pint.log 2>&1); then
    ok "core Pint 代码风格"
  else
    bad "core Pint 未通过"
    tail -6 /tmp/amn-co-pint.log | sed 's/^/      /'
  fi
else
  warn "packages/core 未装 pint，跳过其代码风格（CI 会跑）"
fi

#
# packages/aimind-client —— Aimind 平台的客户端 + 8 个 MCP 工具。
#
# ⚠️ 它此前 **0 单测 / 0 PHPStan / 0 Pint**，而且 `require` 里
# **漏了 `laravel/mcp`** —— 8 个工具类都 `extends Laravel\Mcp\Server\Tool`。
# 从 Packagist 装到用户项目里，MCP server 构造时会 Class not found。
#
# 之所以一直没暴露：monorepo 里别的包 require 了 laravel/mcp，
# 于是「恰好有」。又一次「测试环境手动补过的东西掩盖了包真正缺的东西」。
#
if [ -x "$ROOT/packages/aimind-client/vendor/bin/phpunit" ]; then
  if (cd "$ROOT/packages/aimind-client" && vendor/bin/phpunit > /tmp/amn-ac-ut.log 2>&1); then
    AC_LINE=$(grep -oE 'OK \([0-9]+ tests, [0-9]+ assertions\)' /tmp/amn-ac-ut.log | head -1)
    ok "aimind-client PHPUnit ${AC_LINE:-(通过)}"
  else
    bad "aimind-client PHPUnit 失败"
    tail -20 /tmp/amn-ac-ut.log | sed 's/^/      /'
  fi
else
  warn "packages/aimind-client 未装 phpunit，跳过其单测（CI 会跑）"
fi

if [ -x "$ROOT/packages/aimind-client/vendor/bin/phpstan" ]; then
  if (cd "$ROOT/packages/aimind-client" && vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/amn-ac-stan.log 2>&1); then
    ok "aimind-client PHPStan Level 8"
  else
    bad "aimind-client PHPStan 未通过"
    tail -12 /tmp/amn-ac-stan.log | sed 's/^/      /'
  fi
else
  warn "packages/aimind-client 未装 phpstan，跳过其静态分析（CI 会跑）"
fi

if [ -x "$ROOT/packages/aimind-client/vendor/bin/pint" ]; then
  if (cd "$ROOT/packages/aimind-client" && vendor/bin/pint --test > /tmp/amn-ac-pint.log 2>&1); then
    ok "aimind-client Pint 代码风格"
  else
    bad "aimind-client Pint 未通过"
    tail -6 /tmp/amn-ac-pint.log | sed 's/^/      /'
  fi
else
  warn "packages/aimind-client 未装 pint，跳过其代码风格（CI 会跑）"
fi

#
# 官方扩展 region —— 它已经发布到 Packagist（v1.0.1），
# 但此前 **0 单测 / 0 PHPStan / 0 CI**：改坏了没人知道。
#
# ⚠️ 上面第【2】段的 PHPUnit 跑在 `packages/laravel` 下，
# **看不到 region 的测试** —— 所以这里必须单独跑一次。
# 补上后立刻抓到两个真 bug（详见 commit）：
#   · useData([]) 会让内置数据永久消失且无法恢复
#   · storeCode() 零消费方（文档说能存编码，实际永远存名称）
#
#
# ⚠️ 三个工具**各自判断**是否存在。
#
# 第一版只判断了 `vendor/bin/phpunit`，然后无条件跑 phpstan / pint ——
# 本地 vendor 只装了 phpunit 时，会输出两条**假的** ❌
# （「region PHPStan 未通过」但其实是没装）。
# 假红比不检查更糟：它会让人去查一个不存在的错误。
#
if [ -x "$ROOT/packages/region/vendor/bin/phpunit" ]; then
  if (cd "$ROOT/packages/region" && vendor/bin/phpunit > /tmp/amn-rg-ut.log 2>&1); then
    RG_LINE=$(grep -oE 'OK \([0-9]+ tests, [0-9]+ assertions\)' /tmp/amn-rg-ut.log | head -1)
    ok "region PHPUnit ${RG_LINE:-(通过)}"
  else
    bad "region PHPUnit 失败"
    tail -20 /tmp/amn-rg-ut.log | sed 's/^/      /'
  fi
else
  warn "packages/region 未装 phpunit，跳过其单测（CI 会跑）"
fi

if [ -x "$ROOT/packages/region/vendor/bin/phpstan" ]; then
  if (cd "$ROOT/packages/region" && vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/amn-rg-stan.log 2>&1); then
    ok "region PHPStan Level 8"
  else
    bad "region PHPStan 未通过"
    tail -12 /tmp/amn-rg-stan.log | sed 's/^/      /'
  fi
else
  warn "packages/region 未装 phpstan，跳过其静态分析（CI 会跑）"
fi

if [ -x "$ROOT/packages/region/vendor/bin/pint" ]; then
  if (cd "$ROOT/packages/region" && vendor/bin/pint --test > /tmp/amn-rg-pint.log 2>&1); then
    ok "region Pint 代码风格"
  else
    bad "region Pint 未通过"
    tail -6 /tmp/amn-rg-pint.log | sed 's/^/      /'
  fi
else
  warn "packages/region 未装 pint，跳过其代码风格（CI 会跑）"
fi

# 许可证一致性 —— 三处必须一致（根 LICENSE / composer.json 字段 / 包内 LICENSE）。
# 漏改一个包，Packagist 上那个包就显示旧许可；包里没有 LICENSE，
# 用户就拿不到许可全文（Apache-2.0 §4(a) 是明确义务）。
if bash "$ROOT/scripts/check-licenses.sh" > /tmp/amn-lic.log 2>&1; then
  ok "许可证三处一致（$(grep -c '✅' /tmp/amn-lic.log) 项）"
else
  bad "许可证不一致"
  grep -E '❌' /tmp/amn-lic.log | head -8 | sed 's/^/      /'
fi

# core 纯度门禁 —— 这是多框架适配能否成立的前提。
# core 一旦长回 Illuminate 依赖，ThinkPHP 用户就会重新掉进
# 「装上就炸」（全局 env() 冲突）的坑，而这个坑是静默的。
if bash "$ROOT/scripts/check-core-purity.sh" > /tmp/amn-core.log 2>&1; then
  ok "$(tail -1 /tmp/amn-core.log | sed 's/^✅ //')"
else
  bad "core 包混入了 illuminate/laravel 依赖"
  sed 's/^/      /' /tmp/amn-core.log | head -15
fi

# ─────────────────────────────────────────────────
head1 "【3】必需文件"

for f in README.md LICENSE CHANGELOG.md SECURITY.md TRADEMARK.md COMMERCIAL.md AGENTS.md llms.txt; do
  file_assert "$ROOT/$f"
done

# ─────────────────────────────────────────────────
head1 "【4】命名一致性"

# 排除测试文件（VersionTest 故意提到旧名以做防回归断言）
STALE=$(grep -rn "AI-First" "$ROOT" \
      --include="*.md" --include="*.php" --include="*.json" --include="*.txt" 2>/dev/null \
    | grep -v node_modules | grep -v vendor | grep -v ".vitepress/dist" | grep -v "tests/" \
    | wc -l | tr -d ' ')

if [ "$STALE" = "0" ]; then ok "无残留旧项目名"; else bad "发现 $STALE 处旧项目名残留"; fi

# ─────────────────────────────────────────────────
head1 "【5】CI 与文档站"

for f in .github/workflows/ci.yml .github/workflows/deploy-docs.yml; do
  file_assert "$ROOT/$f"
done

if (cd "$ROOT/docs" && DOCS_BASE=/aimanong/ npm run build > /tmp/amn-db.log 2>&1); then
  ok "文档站可构建"
else
  bad "文档站构建失败"
  sed 's/^/      /' /tmp/amn-db.log | tail -15
fi

# ─────────────────────────────────────────────────
head1 "【6】端到端测试"

if [ "$WITH_E2E" = "1" ]; then
  cd "$ROOT" || exit 1

  # demo 项目路径（可按需调整）
  export DEMO_PATH="${DEMO_PATH:-$ROOT/../demo-app}"

  # ⚠️ 退出码 + **汇总行**双重判定。
  #
  # 只信退出码是不够的：实测遇到过 e2e 在 `require vendor/autoload.php` 阶段
  # 就挂掉（demo 的 autoloader 指向已删除的文件），退出码却是 0 ——
  # 于是这里打印「✅ framework-e2e 通过」，而实际上一个断言都没跑。
  #
  # 汇总行 '通过 N / N' 只会在全部跑完时打印，所以用它兜底。
  R=""
  if php tests/e2e/framework-e2e.php > /tmp/amn-e2e.log 2>&1; then
    R=$(grep -oE '通过 [0-9]+ / [0-9]+' /tmp/amn-e2e.log | tail -1)
  fi

  if [ -n "$R" ]; then
    ok "framework-e2e $R"
  else
    bad "framework-e2e 失败（未产出汇总行 = 没跑完）"
    # 只列出失败项 —— 全量日志太长，且 CI 上无意义
    grep -E '❌|Fatal|Error|Failed to open' /tmp/amn-e2e.log | head -20 | sed 's/^/      /'
  fi

  # 生成的 types.ts 能否通过真实 tsc 编译。
  # 光断言字符串存在抓不到「引用的类型没定义」「基类型与产出不符」这类问题。
  if bash tests/e2e/tsc-check.sh > /tmp/amn-tsc.log 2>&1; then
    ok "$(tail -1 /tmp/amn-tsc.log)"
  else
    bad "tsc-check 失败"
    # 回显内容 —— 只说「见 /tmp/xxx.log」在 CI 上等于什么都看不到
    sed 's/^/      /' /tmp/amn-tsc.log | head -20
  fi

  #
  # 检查 demo 服务。
  #
  # ⚠️ 服务没起时**必须报错，不能静默跳过**。
  #
  # 原先是 warn「跳过 http-e2e」就过去了 —— 那等于把「没测」
  # 伪装成「通过」。真实后果：v1.5.0/v1.6.0 两个大版本新增了 7 项能力，
  # e2e 覆盖为 0，而发布检查一直是绿的。
  #
  # 确实没法起服务时（如纯 CI 无 DB），显式设 AMN_SKIP_HTTP_E2E=1 才放行。
  #
  if curl -s -o /dev/null -m 5 "http://localhost:8899/admin/auth/login" 2>/dev/null; then
    if php tests/e2e/http-e2e.php > /tmp/amn-http.log 2>&1; then
      R2=$(grep -oE '通过 [0-9]+ / [0-9]+' /tmp/amn-http.log | tail -1)
      ok "http-e2e ${R2:-通过}"
    else
      bad "http-e2e 失败"
      grep -E '❌|Fatal|Error' /tmp/amn-http.log | head -15 | sed 's/^/        /'
    fi
  elif [ "${AMN_SKIP_HTTP_E2E:-0}" = "1" ]; then
    warn "demo 服务未运行，且已显式设置 AMN_SKIP_HTTP_E2E=1，跳过 http-e2e"
  else
    bad "demo 服务未运行（localhost:8899）—— 无法验证真实 HTTP 行为。
         请先启动：cd <demo-app> && php artisan serve --port=8899
         确实无法启动时，显式设 AMN_SKIP_HTTP_E2E=1 跳过（会记入风险）"
  fi
  # ThinkPHP 侧端到端：从零搭的 TP 项目里 /admin 真的能打开。
  #
  # 存在的理由：模板转换器出的问题**不报错** —— 退出码 0、4 条规则全过、
  # think-template 也能编译，产物只是渲染得不对。实测踩过三次
  # （{volist} 字符串键 % 2 / @switch 只标注不转换 / 转换器正则错误）。
  # 单测、PHPStan、代码风格、模板规则全都拦不住，唯一的裁判是真跑一遍。
  if [ -f "$ROOT/../thinkphp-demo/vendor/autoload.php" ]; then
    R3=""
    if php tests/e2e/thinkphp-e2e.php > /tmp/amn-tp-e2e.log 2>&1; then
      R3=$(grep -oE '通过 [0-9]+ / [0-9]+' /tmp/amn-tp-e2e.log | tail -1)
    fi

    if [ -n "$R3" ]; then
      ok "thinkphp-e2e $R3"
    else
      bad "thinkphp-e2e 失败（未产出汇总行 = 没跑完）"
      grep -E '❌|错误:|Fatal|Error' /tmp/amn-tp-e2e.log | head -15 | sed 's/^/        /'
    fi
  elif [ "${AMN_SKIP_TP_E2E:-0}" = "1" ]; then
    warn "ThinkPHP 演示项目不存在，且已显式设置 AMN_SKIP_TP_E2E=1，跳过"
  else
    bad "ThinkPHP 演示项目不存在（../thinkphp-demo）—— 无法验证 TP 侧页面。
         请先跑：bash scripts/setup-thinkphp-demo.sh
         确实不跑时，显式设 AMN_SKIP_TP_E2E=1 跳过（会记入风险）"
  fi
else
  warn "跳过端到端（加 --with-e2e 启用，需 demo 环境）"
fi

# ─────────────────────────────────────────────────
head1 "【7】需人工执行的验证"

echo "  以下三项需人工发起，见 internal-docs/发布前验证流程.md："
echo "    3. AI 实测      —— 验证 AI 纠错能力（目标：纠错贡献 100%）"
echo "    4. 真实场景验证  —— 验证能力覆盖度（目标：满足率 ≥90%）"
echo "    5. 插件开发      —— 验证扩展机制"
echo
echo "  ⚠️  约束：实测期间框架必须冻结，不要并发修改。"

# ─────────────────────────────────────────────────
head1 "【8】Composer 包发布连通性"

# 背景：文档教的 `composer require aimanong/framework` 曾经**跑不通**
# （Packagist 无此包 + 仓库根目录无 composer.json）。
# 这是宪法第六条（对外表述无失实）的违规，且性质严重：
# 新人按 README 第一步就失败，而内部验证用的是本地 path 仓库所以发现不了。
#
# 这里检查 Packagist 上能否真的解析到，防止文档再次失实。
#
# ⚠️ 2026-10-13 修：原实现用 `curl --max-time 10 | grep -q`，
#    踩了两个叠加的坑，导致**每次发版都误报「包没发布」**：
#      1. Packagist 的 p2 API 从国内实测延迟 3~10s（6 次里 2 次超 10s），
#         `--max-time 10` 太紧 → curl 退出码 28（超时）
#      2. `set -o pipefail` 下，curl 非 0 会让整条管道判定失败
#    结果：网络慢 → 报「Packagist 未收录 —— 文档里的 composer require 跑不通！」
#    结论方向完全反了（网络问题 vs 发版事故），且会拦住发版。
#    一个总在撒谎的关卡，比没有关卡更糟 —— 它会训练人忽略它。
#
#    现在：① 超时放宽到 30s + 重试 ② 用变量而非管道取 body，
#    避开 grep -q 提前退出引发 SIGPIPE ③ **区分「连不上」和「真没有」**：
#    两者的处置完全不同 —— 前者要重试，后者是发版事故。
#
#    ⚠️ 但仍然**都算失败**：本检查的职责是「确认版本真的发布了」，
#    连不上 = 无法确认 = 不能放行（宪法第五条 可验证）。
#    只是失败信息必须指对根因，否则人会去查错方向。
#    确实遇到断网需要临时绕过时，用显式的 --skip-network，
#    它会在输出里留下醒目的「未验证」痕迹。
fetch_packagist() {
  # 成功时把 JSON 打到 stdout 并返回 0；失败（网络/超时/空响应）返回 1
  local body
  body=$(curl -s --max-time 30 --retry 2 --retry-delay 1 --retry-connrefused \
      "https://repo.packagist.org/p2/aimanong/$1.json" 2>/dev/null) || return 1
  [ -n "$body" ] || return 1
  printf '%s' "$body"
  return 0
}

if [ "$SKIP_NET" -eq 1 ]; then
  warn "已显式跳过 Packagist 连通性检查（--skip-network）"
  warn "→ 版本同步【未验证】。发布前请务必重跑一次不带该参数的检查。"
else
for pkg in core laravel framework region; do
  if ! body=$(fetch_packagist "$pkg"); then
    bad "连不上 Packagist，无法确认 aimanong/${pkg} 是否发布（测了 3 次）"
    echo "       → 这是网络问题，不是发版问题。检查网络后重跑本脚本。"
    echo "       → 确认是断网而非发版遗漏时，才可用 --skip-network 临时绕过。"
    continue
  fi
  # 用 case 而非 `echo | grep -q`：无子进程、无 SIGPIPE 风险
  case "$body" in
    *'"aimanong/'"$pkg"'"'*) ok "Packagist 已收录 aimanong/${pkg}" ;;
    *)
      # 「查不到」有两种可能，但**都无法从一次 API 调用区分**：
      #   ① 从未发布过（如刚拆出来的 core）
      #   ② 曾被撤下
      # 这里统一 warn 而不 bad，理由是：真正会伤到用户的是
      # 「发了 framework，但它依赖的 core 不在 Packagist」——
      # 那个由 publish-framework.sh 的依赖守卫**硬拦**，比这里更靠近风险点。
      # 而一旦该包首次发布，本项就自动变成实打实的版本比对（不是跳过）。
      warn "Packagist 查不到 aimanong/${pkg} —— 可能尚未首次发布"
      echo "       → 若是首次发布：属正常，发布后本项自动生效。"
      echo "       → 发 framework 前请先发它（publish-framework.sh 会硬拦）。"
      ;;
  esac
done

# ⚠️ 最容易漏的一步：tag 推了主仓库，但忘了跑 publish-framework.sh。
# 此时 Packagist 还是旧版本，且**不会报错** —— 用户装到的是旧代码。
#
# ── 2026-10-13 改：判定条件收紧为「打了 tag 且该 tag 属于这个包」 ──
#
# 原先是直接比 `composer.json 的 version` 与 Packagist 最新版。
# 问题：**开发过程中版本领先 Packagist 是常态** —— 每次 bump 版本到真正发布
# 之间都会领先，于是 CI 长期挂红，而它红得没有信息量（拆包期间就踩到：
# framework 1.9.0 依赖的 laravel 还没发布，framework 自然发不出去）。
#
# 真正要拦的事故只有一个：**「打了 tag 却没发」** —— 那时包状态是「已宣布发布」，
# 而用户在 Packagist 上装到的还是旧代码。
#
# 所以判定改成三步，缺一不可：
#   ① 本地存在 tag `v<version>`
#   ② 该 tag 上确实有 `packages/<pkg>/composer.json`
#   ③ 且它的 version 与 tag 名一致（说明这个 tag 就是本包的发行点）
# 三条都满足 → Packagist 上必须有这个版本，否则就是发版遗漏，硬拦。
#
# 注意 ②③ 是必要的：本仓库的 tag 是**全仓库**的（v1.0.0 同时对应
# core/laravel/region 等多个包），不加这两条会把「别的包的 tag」误判成本包的。
echo ""
echo "  发行同步状态（只对已打 tag 的版本做硬校验）："
for pkg in core laravel framework region; do
  local_ver=$(python3 -c "import json;print(json.load(open('$ROOT/packages/${pkg}/composer.json')).get('version',''))" 2>/dev/null || echo "")
  if [ -z "$local_ver" ]; then continue; fi

  tag="v${local_ver}"

  # ① 有没有这个 tag
  if ! git -C "$ROOT" rev-parse -q --verify "refs/tags/${tag}" >/dev/null 2>&1; then
    echo "  ${C_Y}·${C_N}  ${pkg} ${local_ver}：未打 tag，视为开发中，跳过发行校验"
    continue
  fi

  # ② 该 tag 上有没有本包的 composer.json
  tagged_ver=$(git -C "$ROOT" show "${tag}:packages/${pkg}/composer.json" 2>/dev/null \
      | python3 -c "import sys,json
try: print(json.load(sys.stdin).get('version',''))
except: print('')" 2>/dev/null || echo "")

  # ③ 版本是否对得上
  if [ "$tagged_ver" != "$local_ver" ]; then
    echo "  ${C_Y}·${C_N}  ${pkg} ${local_ver}：tag ${tag} 不属于本包（该 tag 上是 '${tagged_ver:-无}'），跳过"
    continue
  fi

  if ! body=$(fetch_packagist "$pkg"); then
    bad "${pkg}: 连不上 Packagist，无法核对 ${tag}（网络问题，非发版问题）"
    continue
  fi

  remote_ver=$(printf '%s' "$body" | python3 -c "import sys,json
try:
    d=json.load(sys.stdin)
    vs=[v['version'].lstrip('v') for v in d['packages']['aimanong/${pkg}'] if not v['version'].startswith('dev')]
    print('${local_ver}' if '${local_ver}' in vs else (sorted(vs)[-1] if vs else ''))
except: print('')" 2>/dev/null || echo "")

  if [ "$remote_ver" = "$local_ver" ]; then
    ok "${pkg}: ${tag} 已发布（Packagist 有 ${local_ver}）"
  elif [ -z "$remote_ver" ]; then
    bad "${pkg}: 打了 ${tag} 但 Packagist 上查不到任何版本 —— 发版遗漏"
    echo "       → 需运行: ./scripts/publish-framework.sh ${pkg}"
  else
    # 用 bad 而非 warn：这一条就是「发版遗漏」，必须拦住。
    # 只 warn 的话 pre-release 仍显示"全部通过"，
    # 而 Packagist 上用户装到的还是旧代码。
    bad "${pkg}: 打了 ${tag} 但 Packagist 上最新是 ${remote_ver} —— 发版遗漏"
    echo "       → 需运行: ./scripts/publish-framework.sh ${pkg}"
  fi
done
fi

# ─────────────────────────────────────────────────
printf "\n${C_B}"
if [ "$FAIL" -eq 0 ]; then
  printf "${C_G}✅ 自动化检查全部通过${C_N}\n"
  echo "   下一步：执行第 7 节的人工验证项"
else
  printf "${C_R}❌ 存在未通过项，请先修复${C_N}\n"
fi
printf "${C_B}\n"

exit $FAIL
