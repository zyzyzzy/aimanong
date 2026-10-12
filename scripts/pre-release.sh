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
FW="$ROOT/packages/framework"
FAIL=0
WITH_E2E=0

[ "${1:-}" = "--with-e2e" ] && WITH_E2E=1

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

V_COMPOSER=$(python3 -c "import json;print(json.load(open('$FW/composer.json'))['version'])" 2>/dev/null || echo "?")
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

if ./vendor/bin/phpstan analyse --no-progress > /tmp/amn-st.log 2>&1; then
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

  if php tests/e2e/framework-e2e.php > /tmp/amn-e2e.log 2>&1; then
    R=$(grep -oE '通过 [0-9]+ / [0-9]+' /tmp/amn-e2e.log | tail -1)
    ok "framework-e2e ${R:-通过}"
  else
    bad "framework-e2e 失败"
    # 只列出失败项 —— 全量日志太长，且 CI 上无意义
    grep -E '❌|Fatal|Error' /tmp/amn-e2e.log | head -20 | sed 's/^/      /'
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
printf "\n${C_B}"
if [ "$FAIL" -eq 0 ]; then
  printf "${C_G}✅ 自动化检查全部通过${C_N}\n"
  echo "   下一步：执行第 7 节的人工验证项"
else
  printf "${C_R}❌ 存在未通过项，请先修复${C_N}\n"
fi
printf "${C_B}\n"

exit $FAIL
