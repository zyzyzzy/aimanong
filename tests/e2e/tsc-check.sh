#!/usr/bin/env bash
#
# 用真实 TypeScript 编译器验证生成的 types.ts。
#
# ## 为什么需要这个脚本
#
# types.ts 的存在理由是「端到端类型安全」，但此前**从来没有被编译过**。
# 实际编译一次就发现两类问题：
#   1. 引用的基类型（AimanongGridSchema 等）根本没定义
#   2. 基类型的字段与 emitter 真实产出不符
#      （缺 jsonType、tree 实际是 null、空 props 是 [] 不是 {}）
#
# 单元测试里断言「字符串存在」是抓不到这些的 —— 只有真编译才算数。
#
# 依赖：typescript（无则自动跳过，不阻塞；CI 里会装）
#
# 用法：bash tests/e2e/tsc-check.sh [demo-app 路径]

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEMO="${1:-${DEMO_PATH:-$ROOT/../demo-app}}"
TYPES="$DEMO/resources/aimanong/types.ts"

if [ ! -f "$TYPES" ]; then
  echo "⚠️  找不到 $TYPES —— 先在该项目执行 php artisan aimanong:schema"
  exit 1
fi

# 找一个可用的 tsc
TSC=""
for c in \
  "$ROOT/docs/node_modules/.bin/tsc" \
  "$DEMO/node_modules/.bin/tsc" \
  "$(command -v tsc 2>/dev/null || true)"
do
  if [ -n "$c" ] && [ -x "$c" ]; then TSC="$c"; break; fi
done

if [ -z "$TSC" ]; then
  echo "⚠️  未找到 tsc —— 跳过（安装：npm i -D typescript）"
  exit 0
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cp "$TYPES" "$WORK/types.ts"

if OUT=$("$TSC" --noEmit --strict --target ES2020 "$WORK/types.ts" 2>&1); then
  echo "✅ types.ts 通过 tsc --strict 编译（$(wc -l < "$TYPES" | tr -d ' ') 行）"
  exit 0
fi

echo "❌ types.ts 编译失败："
echo "$OUT" | head -30
exit 1
