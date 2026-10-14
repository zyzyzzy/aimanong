#!/usr/bin/env bash
#
# 许可证一致性检查。
#
# ## 为什么需要
#
# 拆包之后每个包**独立发布**，各带一份 LICENSE。三处必须一致：
#
#   ① 根 LICENSE 的实际内容（是哪个许可）
#   ② 每个 packages/*/composer.json 的 license 字段（Packagist 上显示的就是它）
#   ③ 每个 packages/*/LICENSE 文件（Apache-2.0 §4(a) 要求向接收者提供副本）
#
# 漏掉任意一处都是真实发生过的那类事故：
#   · license 字段漏改某个包 → Packagist 上那个包显示旧许可
#   · 包里没有 LICENSE 文件 → 用户拿不到许可全文（MIT 下不规范，
#     Apache-2.0 下是明确义务）
#
# 用法：bash scripts/check-licenses.sh

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

RED='\033[0;31m'; GREEN='\033[0;32m'; BOLD='\033[1m'; NC='\033[0m'
[ -t 1 ] || { RED=''; GREEN=''; BOLD=''; NC=''; }

FAIL=0
PACKAGES="core laravel framework region thinkphp"

printf "${BOLD}许可证一致性检查${NC}\n"

# ── ① 识别根 LICENSE 是哪个许可，并推出 SPDX 标识 ──
if [ ! -f "$ROOT/LICENSE" ]; then
    printf "${RED}❌ 根 LICENSE 不存在${NC}\n"
    exit 1
fi

IDENT=""
if grep -q "Apache License" "$ROOT/LICENSE" && grep -q "Version 2.0, January 2004" "$ROOT/LICENSE"; then
    IDENT="Apache-2.0"
    # 全文完整性：Apache-2.0 有固定的条款标题
    for marker in "END OF TERMS AND CONDITIONS" "APPENDIX: How to apply"; do
        if ! grep -q "$marker" "$ROOT/LICENSE"; then
            printf "${RED}❌ LICENSE 缺少「%s」——不是 Apache-2.0 完整全文${NC}\n" "$marker"
            FAIL=1
        fi
    done
elif grep -q "^MIT License" "$ROOT/LICENSE"; then
    IDENT="MIT"
else
    printf "${RED}❌ 无法识别根 LICENSE 的许可类型${NC}\n"
    exit 1
fi

[ "$FAIL" -eq 0 ] && printf "  ${GREEN}✅${NC} 根 LICENSE = %s（全文完整）\n" "$IDENT"

# ── ② 每个包的 license 字段 ──
for pkg in $PACKAGES; do
    cfg="$ROOT/packages/$pkg/composer.json"

    [ -f "$cfg" ] || { printf "${RED}❌ 缺 %s${NC}\n" "${cfg#"$ROOT"/}"; FAIL=1; continue; }

    field=$(python3 -c "
import json, sys
d = json.load(open('$cfg'))
v = d.get('license', '')
print(v if isinstance(v, str) else ','.join(v))" 2>/dev/null || echo "")

    if [ -z "$field" ]; then
        printf "${RED}❌ %-10s composer.json 没有 license 字段${NC}\n" "$pkg"
        FAIL=1
    elif [ "$field" != "$IDENT" ]; then
        printf "${RED}❌ %-10s license=%s，但根 LICENSE 是 %s${NC}\n" "$pkg" "$field" "$IDENT"
        FAIL=1
    else
        printf "  ${GREEN}✅${NC} %-10s composer.json license = %s\n" "$pkg" "$field"
    fi
done

# ── ③ 每个包自带 LICENSE，且与根逐字一致 ──
for pkg in $PACKAGES; do
    f="$ROOT/packages/$pkg/LICENSE"

    if [ ! -f "$f" ]; then
        printf "${RED}❌ %-10s 没有 LICENSE 文件 —— 用户装这个包拿不到许可全文${NC}\n" "$pkg"
        FAIL=1
        continue
    fi

    if ! cmp -s "$ROOT/LICENSE" "$f"; then
        printf "${RED}❌ %-10s LICENSE 与根 LICENSE 不一致（改许可时漏了这个包）${NC}\n" "$pkg"
        FAIL=1
        continue
    fi

    printf "  ${GREEN}✅${NC} %-10s LICENSE 与根逐字一致\n" "$pkg"
done

printf '\n'

if [ "$FAIL" -eq 0 ]; then
    printf "${GREEN}✅ 许可证三处一致（根 LICENSE / composer.json 字段 / 包内 LICENSE）${NC}\n"
else
    printf "${RED}❌ 许可证不一致${NC}\n"
fi

exit "$FAIL"
