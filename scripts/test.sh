#!/usr/bin/env bash
#
# 跑本仓库的单元测试。
#
# ## 为什么有这个脚本
#
# 仓库是 monorepo，**根目录没有 composer.json** —— 所以
# `composer test` 会直接报 `Command "test" is not defined`。
# 而 AGENTS.md 原先教 AI「跑测试：composer test」，
# 于是 AI 的验证步骤第一步就失败（2026-10-16 审计 A8）。
#
# 这里给一个**真的能跑**的统一入口。
#
# 用法：
#   bash scripts/test.sh          # 跑全部
#   bash scripts/test.sh laravel  # 只跑某个包
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ONLY="${1:-}"

pass=0
fail=0
skipped=0

run() {
  local name="$1"; shift
  local dir="$1"; shift

  if [[ -n "$ONLY" && "$name" != "$ONLY" ]]; then
    return 0
  fi

  if [[ ! -d "$dir/vendor" ]]; then
    printf '  ⏭  %-12s 未安装依赖（缺 vendor/），跳过\n' "$name"
    skipped=$((skipped + 1))
    return 0
  fi

  if [[ ! -x "$dir/vendor/bin/phpunit" ]]; then
    printf '  ⏭  %-12s 无 phpunit，跳过\n' "$name"
    skipped=$((skipped + 1))
    return 0
  fi

  local log="/tmp/amn-test-${name}.log"

  if (cd "$dir" && ./vendor/bin/phpunit > "$log" 2>&1); then
    printf '  ✅ %-12s %s\n' "$name" "$(grep -oE 'OK \([0-9]+ tests[^)]*\)' "$log" | head -1)"
    pass=$((pass + 1))
  else
    printf '  ❌ %-12s 失败（日志 %s）\n' "$name" "$log"
    tail -20 "$log" | sed 's/^/       /'
    fail=$((fail + 1))
  fi
}

echo "═══ 单元测试 ═══"

run laravel  "$ROOT/packages/laravel"
run thinkphp "$ROOT/packages/thinkphp"
run core     "$ROOT/packages/core"
run region   "$ROOT/packages/region"

echo
if [[ $fail -gt 0 ]]; then
  echo "❌ $fail 个包失败（通过 ${pass}，跳过 ${skipped}）"
  exit 1
fi

echo "✅ 全部通过（${pass} 个包，跳过 ${skipped}）"
