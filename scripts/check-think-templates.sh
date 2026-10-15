#!/usr/bin/env bash
#
# think-template 产物规则校验。
#
# ## 为什么要有这个脚本
#
# `scripts/blade2think.py` 把 Blade 转成 think-template 模板。但
# **转换成功 ≠ 能编译** —— PoC 阶段踩到的三个坑，每一个都是「转换看着
# 没问题、think-template 编译时才炸」，而且炸法各不相同：
#
#   ① partial 里有 PHP `use`      → PHP 致命错误（编译期内联导致重复声明）
#   ② 注释里有可展开的模板语法     → 无限递归 / 内存耗尽（挂死，不报错）
#   ③ 用 `{:表达式}` 而非原生 PHP  → **静默失效**（原样输出，不执行）
#
# 这三条都**不会**在单测、PHPStan、代码风格里暴露 —— 只有真的让
# think-template 编译一遍才会现形。所以把它们固化成关卡。
#
# ## 一个重要的设计约束：不能误报
#
# 规则①的第一版直接 grep `function`，把 <script> 里的 **JavaScript**
# 函数全报了（10 个模板里 14 处）。一个总在误报的关卡会被忽略 ——
# 所以规则①只看 **PHP 代码块**（{php}…{/php} 与 <?php … ?>）。
#
# 用法：bash scripts/check-think-templates.sh [产物目录]
#       默认 packages/thinkphp/resources/views

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TPL="${1:-$ROOT/packages/thinkphp/resources/views}"

RED='\033[0;31m'; GREEN='\033[0;32m'; BOLD='\033[1m'; NC='\033[0m'
[ -t 1 ] || { RED=''; GREEN=''; BOLD=''; NC=''; }

if [ ! -d "$TPL" ]; then
    printf "${RED}❌ 找不到产物目录 %s${NC}\n" "$TPL"
    printf "   先跑：python3 scripts/blade2think.py\n"
    exit 1
fi

FAIL=0
N=$(find "$TPL" -name '*.html' | wc -l | tr -d ' ')
printf "${BOLD}think-template 产物规则校验${NC}（%s 个模板，%s）\n" "$N" "${TPL#"$ROOT"/}"

# ── ① partial 的 PHP 代码块不得有 use / 函数定义 ──────────
rule1=$(python3 - "$TPL" <<'PYEOF'
import glob, os, re, sys

root = sys.argv[1]
bad = []
php_block = re.compile(r'\{php\}(.*?)\{/php\}|<\?php(.*?)\?>', re.S)

for f in glob.glob(os.path.join(root, 'partials', '**', '*.html'), recursive=True):
    src = open(f, encoding='utf-8').read()
    for m in php_block.finditer(src):
        body = m.group(1) if m.group(1) is not None else m.group(2)
        for line in body.split('\n'):
            t = line.strip()
            if re.match(r'use\s+[A-Za-z_\\][A-Za-z0-9_\\]*\s*;', t):
                bad.append('%s: %s' % (os.path.relpath(f, root), t))
            elif re.match(r'(public\s+|protected\s+|private\s+)?function\s+[A-Za-z_]', t):
                bad.append('%s: %s' % (os.path.relpath(f, root), t[:70]))

print('\n'.join(bad))
PYEOF
)

if [ -n "$rule1" ]; then
    printf "${RED}❌ 规则①：partial 的 PHP 代码块里有 use / 函数定义${NC}\n"
    printf '%s\n' "$rule1" | sed 's|^|     |'
    printf "     → {include} 是编译期内联，重复声明即致命错误。\n"
    printf "       应上提到页面级模板（blade2think.py 会自动做）。\n"
    FAIL=1
else
    printf "  ${GREEN}✅${NC} 规则①：partial 的 PHP 代码块无 use / 函数定义\n"
fi

# ── ② 注释块里不得有会被展开的模板语法 ────────────────────
rule2=$(python3 - "$TPL" <<'PYEOF'
import glob, os, re, sys

root = sys.argv[1]
bad = []
pats = [r'\{include\b', r'\{\$', r'\{if\b', r'\{foreach\b', r'\{volist\b', r'\{php\}']

for f in glob.glob(os.path.join(root, '**', '*.html'), recursive=True):
    src = open(f, encoding='utf-8').read()
    for m in re.finditer(r'\{/\*(.*?)\*/\}', src, re.S):
        for p in pats:
            if re.search(p, m.group(1)):
                bad.append('%s: 注释内含 %s' % (os.path.relpath(f, root), p))
                break

print('\n'.join(bad))
PYEOF
)

if [ -n "$rule2" ]; then
    printf "${RED}❌ 规则②：注释块里有会被 think-template 展开的语法${NC}\n"
    printf '%s\n' "$rule2" | sed 's|^|     |'
    printf "     → {include} 展开不认注释；自引用示例会变成真的自引用（挂死）。\n"
    FAIL=1
else
    printf "  ${GREEN}✅${NC} 规则②：注释里无会被展开的模板语法\n"
fi

# ── ③ {:...} 只能用于函数调用 / 带过滤器的变量 ────────────
rule3=$(python3 - "$TPL" <<'PYEOF'
import glob, os, re, sys

root = sys.argv[1]
ok_fn = re.compile(r'^[A-Za-z_\\][A-Za-z0-9_\\]*\s*\(.*\)(\s*\|[a-z_]+)*$', re.S)
ok_var = re.compile(
    r'^\$[A-Za-z_][A-Za-z0-9_]*'
    r'((\s*\[[^\]]*\])|(\s*->[A-Za-z_][A-Za-z0-9_]*))*'
    r'(\s*\|[a-z_]+)*$', re.S)
bad = []

for f in glob.glob(os.path.join(root, '**', '*.html'), recursive=True):
    src = open(f, encoding='utf-8').read()
    body = re.sub(r'\{/\*.*?\*/\}', '', src, flags=re.S)
    for m in re.finditer(r'\{:([^}]*)\}', body, re.S):
        inner = m.group(1).strip()
        if ok_fn.match(inner) or ok_var.match(inner):
            continue
        bad.append('%s: {: %s }' % (os.path.relpath(f, root), inner[:60]))

print('\n'.join(bad[:20]))
PYEOF
)

if [ -n "$rule3" ]; then
    printf "${RED}❌ 规则③：{:...} 里出现了非函数调用表达式${NC}\n"
    printf '%s\n' "$rule3" | sed 's|^|     |'
    printf "     → {:1+1} 这类不会执行，会原样输出（静默失效）。\n"
    printf "       任意表达式应转成 <?php echo ...; ?>。\n"
    FAIL=1
else
    printf "  ${GREEN}✅${NC} 规则③：{:...} 里没有会静默失效的表达式\n"
fi

# ── ④ 代码区不得残留 Blade 指令 ──────────────────────────
rule4=$(python3 - "$TPL" <<'PYEOF'
import glob, os, re, sys

root = sys.argv[1]
pat = re.compile(r'@(include|extends|section|yield|endsection|endif|endforeach|php|csrf|json)\b')
bad = []

for f in glob.glob(os.path.join(root, '**', '*.html'), recursive=True):
    src = open(f, encoding='utf-8').read()
    body = re.sub(r'\{/\*.*?\*/\}', '', src, flags=re.S)
    if pat.search(body):
        bad.append(os.path.relpath(f, root))

print('\n'.join(bad))
PYEOF
)

if [ -n "$rule4" ]; then
    printf "${RED}❌ 规则④：代码区残留 Blade 指令（注释里的示例不算）${NC}\n"
    printf '%s\n' "$rule4" | sed 's|^|     |'
    printf "     → 转换不完整，这些指令 think-template 不认识。\n"
    FAIL=1
else
    printf "  ${GREEN}✅${NC} 规则④：代码区无 Blade 指令残留\n"
fi

# ── 规则⑤：布局占位符不得用自闭合 block ──
#
# think-template 的 {block name="x" /}（自闭合）**不会被替换**：
# 它不报错，但替换那一步匹配不到 → 布局照常渲染、**子模板内容整块丢失**。
#
# 实测踩到：控制台页面只剩侧边栏 + 主题面板，正文一个字都没有，
# 而 e2e 全绿（断言只查布局里的串）。
# 来源是 blade2think.py 把 @yield/@stack 转成了自闭合形式 —— 已一并修。
rule5=$(python3 - "$TPL" <<'PYEOF'
import glob, os, re, sys

root = sys.argv[1]
# 只查 block：{block ... /}
pat = re.compile(r'\{block\b[^}]*?/\}')
bad = []

for f in glob.glob(os.path.join(root, '**', '*.html'), recursive=True):
    src = open(f, encoding='utf-8').read()
    for m in pat.finditer(src):
        line = src[:m.start()].count('\n') + 1
        bad.append(f"{os.path.relpath(f, root)}:{line}  {m.group(0)}")

print('\n'.join(bad))
PYEOF
)

if [ -n "$rule5" ]; then
    printf "${RED}❌ 规则⑤：布局占位符用了自闭合 {block ... /}${NC}\n"
    printf '%s\n' "$rule5" | sed 's|^|     |'
    printf "     → think-template 不会替换它：不报错，但子模板内容**整块丢失**。\n"
    printf "     → 改成 {block name=\"x\"}{/block}（非自闭合）。\n"
    FAIL=1
else
    printf "  ${GREEN}✅${NC} 规则⑤：无自闭合 block 占位符\n"
fi

printf '\n'
if [ "$FAIL" -eq 0 ]; then
    printf "${GREEN}✅ 模板产物通过全部硬性规则${NC}\n"
else
    printf "${RED}❌ 模板产物违反硬性规则，think-template 编译会炸${NC}\n"
fi

exit "$FAIL"
