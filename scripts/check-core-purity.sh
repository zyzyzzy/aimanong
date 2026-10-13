#!/usr/bin/env bash
#
# core 纯度门禁 —— `packages/core` 不得依赖 illuminate / laravel。
#
# ## 为什么需要一条**可执行**的门禁
#
# 拆包最容易失败的方式不是搬错文件，而是**搬完之后 core 里又悄悄长回
# Illuminate 依赖**。AI 生成代码时尤其容易：它看到别处 `use Illuminate\Support\Str`
# 就会照抄，而这一行会让 ThinkPHP 用户重新掉进
# 「装上就炸」（全局 env() 冲突）的坑里。
#
# 文档拦不住这件事，只有 CI 能。这条门禁把「core 是框架无关的」
# 从**声明**变成**运行时事实**（宪法第三条：零隐式）。
#
# ## 检查两类
#
#   ① `use Illuminate\...` / `use Laravel\...`        —— 真正的耦合机制
#   ② 代码行（非注释）里的 `Illuminate\` / `Laravel\` 全限定名
#
# 注释与文档块里的提及**不算违规** —— core 的注释本来就要解释
# 「哪个 Eloquent 类型被抽象掉了」，那是文档，不是依赖。
#
# 用法：
#   bash scripts/check-core-purity.sh

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CORE="$ROOT/packages/core/src"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BOLD='\033[1m'; NC='\033[0m'
[ -t 1 ] || { RED=''; GREEN=''; YELLOW=''; BOLD=''; NC=''; }

if [ ! -d "$CORE" ]; then
    printf "${RED}❌ 找不到 %s${NC}\n" "$CORE"
    exit 1
fi

fail=0

printf "${BOLD}core 纯度检查：%s${NC}\n" "${CORE#"$ROOT"/}"

# ── ① use 导入 ────────────────────────────────
imports=$(grep -rnE '^[[:space:]]*use[[:space:]]+(Illuminate|Laravel)\\' "$CORE" 2>/dev/null || true)

if [ -n "$imports" ]; then
    printf "${RED}❌ core 里出现了 illuminate/laravel 的 use 导入：${NC}\n"
    printf '%s\n' "$imports" | sed "s|$ROOT/||;s|^|     |"
    printf '\n'
    printf "   → 正确做法：core 只依赖 \`Aimanong\\\\Contracts\\\\*\` 契约，\n"
    printf "     具体实现由框架驱动包通过 \`Aimanong\\\\Support\\\\Drivers\` 注册。\n"
    printf "     见 internal-docs/多框架适配-拆包实施计划.md\n\n"
    fail=1
fi

# ── ② 非注释行里的全限定名 ─────────────────────
# 注释行：以 * 开头（文档块内）、// 开头、或 /* 开头
fqcns=$(grep -rnE '\b(Illuminate|Laravel)\\' "$CORE" 2>/dev/null \
    | grep -vE ':[0-9]+:[[:space:]]*(\*|//|/\*)' \
    || true)

if [ -n "$fqcns" ]; then
    printf "${RED}❌ core 代码行里出现了 illuminate/laravel 全限定名：${NC}\n"
    printf '%s\n' "$fqcns" | sed "s|$ROOT/||;s|^|     |"
    printf '\n'
    fail=1
fi

# ── 结果 ──────────────────────────────────────
if [ "$fail" -eq 0 ]; then
    n=$(find "$CORE" -name '*.php' | wc -l | tr -d ' ')
    l=$(cat $(find "$CORE" -name '*.php') 2>/dev/null | wc -l | tr -d ' ')
    printf "${GREEN}✅ core 零 illuminate/laravel 依赖（%s 文件 / %s 行）${NC}\n" "$n" "$l"
fi

exit "$fail"
