#!/usr/bin/env python3
r"""
Blade → think-template 转换器（Aimanong 的 ThinkPHP 驱动用）。

把 `packages/laravel/resources/views/**.blade.php` 转成
`packages/thinkphp/resources/views/**.html`。

## 为什么是「生成物」而不是「手写两套」

Laravel 侧那套 Blade 模板是**唯一真源** —— 手写第二套必然漂移。
本工具在发版时跑一次，产出 TP 侧模板；`scripts/check-think-templates.sh`
再对产物做规则校验，保证它真的能被 think-template 编译。

## 三条硬规则（都是 PoC 阶段**实测踩出来的**，不是推测）

① **partial 里不能有 `use` / 函数定义**
   think-template 的 `{include}` 是**编译期内联**（Blade 是运行期独立渲染）。
   同一 partial 被 include 两次，它的 `use` 就会在同一份编译产物里出现两次 →
   `PHP Fatal error: Cannot use X as Y because the name is already in use`。
   实测：`partials/layout` 与 `partials/theme-panel` 各写了一行
   `use Aimanong\Ui\ThemeConfig;`，一次炸掉 4 个页面模板。

② **注释里不能出现可被展开的 `@include` 用法示例**
   `{include}` 展开**不认注释块**。`partials/icon` 的文档注释里正好写了
   自引用示例 → 转换后变成真的自引用 → **无限递归，内存耗尽**（不是报错，是挂死）。

③ **`{{ expr }}` 必须走原生 PHP，不能用 `{:...}`**
   think-template 的 `{:...}` 只认**函数调用**语法：`{:1+1}` 会**原样输出不执行**
   （又一个静默失效）。而 `tpl_deny_php` 默认 false，原生 PHP 可直接用 ——
   所以表达式一律转成 `<?php echo expr; ?>`，语义等价。

用法：
    python3 scripts/blade2think.py [源目录] [目标目录]
      默认：packages/laravel/resources/views → packages/thinkphp/resources/views

退出码：0 = 转换完成（可能仍有「待人工核对」项，**它们不是失败**）；
        1 = 源目录不存在等硬错误。
"""

import glob
import os
import re
import shutil
import sys
from collections import Counter

VIEWS = sys.argv[1] if len(sys.argv) > 1 else 'packages/laravel/resources/views'
OUT = sys.argv[2] if len(sys.argv) > 2 else 'packages/thinkphp/resources/views'

unhandled = []

def conv(src: str, name: str, hoisted: dict) -> tuple[str, list[str]]:
    """返回 (转换后文本, 该文件里未能机械处理的项)"""
    todo = []
    s = src

    # ⓪ 先把 Blade 注释抽出来改成占位符，最后再还原。
    #    原因（实测踩到）：think-template 的 {include} 展开不认注释块，
    #    注释里若写了 @include 用法示例（本项目的模板就是这么写的），
    #    会被真的展开 —— 自引用时直接无限递归爆栈。
    def find_paren(text, start):
        """start 指向 '('，返回匹配的 ')' 下标。嵌套括号必须配对，
        否则 @if($a && f($b)) 会被截成 @if($a && f($b) —— 真实踩过。"""
        depth = 0
        quote = None
        for i in range(start, len(text)):
            ch = text[i]
            if quote:
                if ch == quote and text[i-1] != '\\':
                    quote = None
                continue
            if ch in '"\'':
                quote = ch
            elif ch == '(':
                depth += 1
            elif ch == ')':
                depth -= 1
                if depth == 0:
                    return i
        return -1

    def bal_sub(pattern, s_):
        """把 pattern(...) 形式的指令按「配对括号」整体替换，pattern 是 @if 这类前缀"""
        out, pos = [], 0
        while True:
            m = re.compile(pattern + r'\s*\(').search(s_, pos)
            if not m:
                out.append(s_[pos:]); break
            out.append(s_[pos:m.start()])
            close = find_paren(s_, m.end() - 1)
            if close < 0:
                out.append(s_[m.start():]); break
            out.append((m.group(0)[:m.group(0).index('(')], s_[m.end():close]))
            pos = close + 1
        return out

    stash = []
    def _stash(m):
        stash.append(m.group(1))
        return '\x00CMT%d\x00' % (len(stash) - 1)
    n_cmt = len(re.findall(r'\{\{--', s))
    s = re.sub(r'\{\{--(.*?)--\}\}', _stash, s, flags=re.S)
    # 用占位符保护三块「不许碰」的区域：{{-- --}} 之外的 JS/CSS 天然安全（已实测），
    # 但 @ 指令和 {{ }} 必须先处理。

    # ① @{{ }} Vue mustache → {{ }}（think-template 不需要转义）
    n = len(re.findall(r'@\{\{', s))
    if n: todo.append(f'Vue mustache 去转义 {n} 处')
    s = re.sub(r'@\{\{(.*?)\}\}', lambda m: '{{' + m.group(1) + '}}', s, flags=re.S)


    # ③ @json($x) → {:json_encode($x)}
    n = len(re.findall(r'@json\s*\(', s))
    if n: todo.append(f'@json→<?php echo json_encode()?> {n} 处')
    s = ''.join(
        (('<?php echo json_encode(' + t[1] + '); ?>') if isinstance(t, tuple) else t)
        for t in bal_sub(r'@json', s))

    # ④ @csrf → {:csrf_token()}
    n = len(re.findall(r'@csrf\b', s))
    if n: todo.append(f'@csrf→{{:csrf_token()}} {n} 处')
    s = s.replace('@csrf', '{:csrf_token()}')

    # ④.5 {!! expr !!} → 原样输出（不转义）。Blade 的 raw echo。
    n_raw = len(re.findall(r'\{!!', s))
    if n_raw: todo.append('Blade 原始输出 {!! !!} %d 处 → <?php echo ... ?>（不转义）' % n_raw)
    # ⚠️ 用 `<?php echo …; ?>` 而不是 `{:$x|raw}`。
    #    实测踩到：think-template 的 `{:…}` 只认函数调用，`{:$path|raw}`
    #    会被编译成对常量 `raw` 的引用 → `Undefined constant "raw"`。
    #    而原生 PHP 输出本来就**不转义**，正好等于 Blade 的 `{!! !!}`。
    s = re.sub(r'\{!!(.*?)!!\}', lambda m: '<?php echo ' + m.group(1).strip() + '; ?>', s, flags=re.S)

    # ⑤ {{ expr }} → {$var}（纯变量）或 <?php echo expr; ?>（任意表达式）
    #    实测：think-template 的 {:...} 只认函数调用语法，`{:1+1}` 会原样输出不执行；
    #    而 tpl_deny_php 默认为 false，原生 PHP 可直接用 —— 表达式走原生 PHP 才语义等价。
    def echo(m):
        inner = m.group(1).strip()
        if re.fullmatch(r'\$[A-Za-z_]\w*', inner):
            return '{$' + inner[1:] + '}'
        return '<?php echo ' + inner + '; ?>'
    s = re.sub(r'\{\{(.*?)\}\}', echo, s, flags=re.S)

    # ⑥ @if / @elseif / @else / @endif
    for n_ in ['if', 'elseif']:
        cnt = len(re.findall('@' + n_ + r'\b', s))
        if cnt:
            todo.append('@%s %d 处' % (n_, cnt))
    def _rebuild(tokens, tag):
        out = []
        for t in tokens:
            if isinstance(t, tuple):
                out.append('{%s %s}' % (tag, t[1]))
            else:
                out.append(t)
        return ''.join(out)
    s = _rebuild(bal_sub(r'@if', s), 'if')
    s = _rebuild(bal_sub(r'@elseif', s), 'elseif')
    s = re.sub(r'@else\b', '{else /}', s)
    s = re.sub(r'@endif\b', '{/if}', s)

    # ⑦ @foreach / @forelse / @empty
    for n_ in ['foreach', 'forelse']:
        c = len(re.findall('@'+n_+r'\b', s))
        if c: todo.append(f'@{n_}→{{volist}} {c} 处（语义需人工核对）')
    def _volist(tokens):
        out = []
        for t in tokens:
            if isinstance(t, tuple):
                inner = t[1]
                m2 = re.match(r'(.*?)\s+as\s+\$?(\w+)\s*=>\s*\$?(\w+)$', inner, re.S)
                if m2:
                    out.append('{volist name="%s" id="%s" key="%s"}' % (m2.group(1).strip(), m2.group(3), m2.group(2)))
                else:
                    m3 = re.match(r'(.*?)\s+as\s+\$?(\w+)$', inner, re.S)
                    out.append('{volist name="%s" id="%s"}' % (m3.group(1).strip(), m3.group(2)) if m3 else '{/* foreach 无法机械转换: %s */}' % inner)
            else:
                out.append(t)
        return ''.join(out)
    s = _volist(bal_sub(r'@foreach', s))
    s = re.sub(r'@endforeach\b', '{/volist}', s)
    # forelse 的 @empty 分支 TP 无直接对应
    if re.search(r'@forelse\b', s):
        s = _volist(bal_sub(r'@forelse', s))
        s = re.sub(r'@empty\b', '{/* @empty 分支：TP 无对应，需人工改写 */}', s)
        s = re.sub(r'@endforelse\b', '{/volist}', s)

    # ⑧ @php / @endphp
    n = len(re.findall(r'@php\b', s))
    if n: todo.append(f'@php→{{php}} {n} 处')
    s = re.sub(r'@php\b', '{php}', s)
    s = re.sub(r'@endphp\b', '{/php}', s)

    # ⑨ @include('a.b', [...]) —— 30/34 带数据数组，属性语法无法完整表达
    out_parts = []
    pos = 0
    while True:
        m = re.compile(r'@include\s*\(').search(s, pos)
        if not m:
            out_parts.append(s[pos:])
            break
        out_parts.append(s[pos:m.start()])
        close = find_paren(s, m.end() - 1)
        if close < 0:
            out_parts.append(s[m.start():])
            break
        args = s[m.end():close]
        parts = [p_.strip() for p_ in re.split(r",(?![^\[\]]*\])", args, maxsplit=1)]
        tpl = parts[0].strip().strip("'\"")
        tpl = re.sub(r'^[A-Za-z_]\w*::', '', tpl)
        file = tpl.replace('.', '/')
        if len(parts) > 1 and parts[1].strip():
            todo.append('@include(%s) 带数据数组 → TP 属性语法无法完整表达' % tpl)
        out_parts.append('{include file="%s" /}' % file)
        pos = close + 1
    s = ''.join(out_parts)

    # ⑩ @extends / @section / @yield / @push / @stack
    if re.search(r'@extends\s*\(', s):
        todo.append('@extends/@section → {extend}/{block}（继承体系需人工核对）')
    s = re.sub(r"@extends\s*\(\s*'([^']*)'\s*\)",
               lambda m: '{extend name="%s" /}' % re.sub(r'^[A-Za-z_]\w*::', '', m.group(1)).replace('.', '/'), s)
    s = re.sub(r"@section\s*\(\s*'(.*?)'\s*\)", r'{block name="\1"}', s)
    s = re.sub(r'@endsection\b', '{/block}', s)
    s = re.sub(r"@yield\s*\(\s*'([^']*)'\s*\)",
               lambda m: '{block name="%s" /}' % re.sub(r'^[A-Za-z_]\w*::','',m.group(1)).replace('.','/'), s)
    if re.search(r'@push\s*\(|@stack\s*\(', s):
        todo.append('@push/@stack 在 TP 无对应机制，需人工改写')
    s = re.sub(r"@push\s*\(\s*'(.*?)'\s*\)", r'{block name="\1"}', s)
    s = re.sub(r'@endpush\b', '{/block}', s)
    s = re.sub(r"@stack\s*\(\s*'(.*?)'\s*\)", r'{block name="\1" /}', s)

    # ⑪ @checked / @switch / @case / @break
    if re.search(r'@checked\b', s):
        todo.append('@checked 在 TP 无对应，需人工展开为 {if}')
    s = re.sub(r'@checked\s*\((.*?)\)', r'{if \1}checked{/if}', s)
    if re.search(r'@switch\b|@case\b', s):
        todo.append('@switch/@case 在 TP 无对应，需人工展开为 {if}')
    s = re.sub(r'@switch\s*\((.*?)\)', r'{/* switch \1 → 需人工改写为 if */}', s, flags=re.S)
    s = re.sub(r'@case\s*\((.*?)\)', r'{/* case \1 */}', s)
    s = re.sub(r'@break\b', '', s)
    s = re.sub(r'@endswitch\b', '', s)

    # ⑪.5 Laravel 专属的**表达式**改写成框架无关调用。
    #      app()->version() 在 TP 里返回的是 think\App（没有 version 方法）——
    #      直接留着就是运行时致命错误。模板是生成物，这里改写不影响 Blade 真源。
    s = re.sub(r"app\(\)->version\(\)", r"\\Aimanong\\Aimanong::version()", s)

    # ⑫ Laravel 专属 helper（TP 里不存在或语义不同）
    for h in ['csrf_token()', 'old(', 'url(', '$errors', 'app()->version()', 'asset(']:
        c = len(re.findall(re.escape(h), s))
        if c: todo.append(f'Laravel helper `{h}` × {c} → 需框架抽象层提供')

    # ⑫.5 partial 里的 use 声明必须上提：
    #      TP 的 {include} 是【编译期内联】，同一 partial 被 include 两次，
    #      它的 use 语句就会在同一份编译产物里出现两次 → PHP 致命错误
    #      "Cannot use X as Y because the name is already in use"（实测踩到）。
    if name.startswith('partials/'):
        # ⚠️ 规则①：partial 里的 use 必须摘掉并上提到页面级。
        #    think-template 的 {include} 是**编译期内联** —— 同一 partial 被
        #    include 两次，它的 use 就在同一份编译产物里出现两次 →
        #    `PHP Fatal error: Cannot use X as Y because the name is already in use`。
        #    实测：partials/layout 与 partials/theme-tab 各一行，一次炸掉 4 个页面模板。
        for um in sorted(set(re.findall(r'\buse\s+([A-Za-z_\\][\w\\]*)\s*;', s))):
            hoisted.setdefault(um, set()).add(name)
            todo.append('partial 里的 `use %s;` 已摘除，短类名改写成全限定名' % um)

            # ⚠️ 不能「上提到页面级」—— 实测发现 `{php} use X; {/php}` **不生效**：
            #    use 语句必须是文件顶层，而 think-template 把 {php} 块编译进
            #    生成文件的中部，use 被忽略 → 引用处报 Class "X" not found。
            #    所以改成把**短类名替换成全限定名**，从根上不需要 use。
            short = um.split('\\')[-1]
            # 用 lambda 而不是替换串：$um 里含反斜杠，替换串会把 \T 之类当转义
            s = re.sub(
                r'(?<![\\\w])' + re.escape(short) + r'(::|\()',
                lambda m, full=um: '\\' + full + m.group(1),
                s,
            )

        s = re.sub(r'[ \t]*use\s+[A-Za-z_\\][\w\\]*\s*;[ \t]*\n', '', s)

    # ⓪ 还原注释（并且把注释里的 @include 用法示例也写成 TP 形式）
    if n_cmt:
        todo.append('Blade 注释 %d 处 → TP 注释（注意：TP 的 {include} 展开不认注释，' % n_cmt
                    + '注释里不能留可被展开的 include）')
    for i, body in enumerate(stash):
        # ⚠️ 规则②：注释里的 @include 用法示例一律保持原样。
        #    @include 不会被 TP 的 {include} 正则命中，留原样最安全。
        #    实测过：改写成 {include ...} 反而触发无限递归（内存耗尽）。
        s = s.replace('\x00CMT%d\x00' % i, '{/*' + body + '*/}')
    return s, todo


def main():
    shutil.rmtree(OUT, ignore_errors=True)
    os.makedirs(OUT, exist_ok=True)
    files = sorted(glob.glob(VIEWS + '/**/*.blade.php', recursive=True))
    all_todo = []
    hoisted: dict = {}
    print(f"{'模板':<34} {'行数':>5} {'待人工项':>8}")
    print('-' * 60)
    for f in files:
        rel = os.path.relpath(f, VIEWS)
        src = open(f, encoding='utf-8').read()
        out, todo = conv(src, rel, hoisted)
        dst = os.path.join(OUT, rel.replace('.blade.php', '.html'))
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        open(dst, 'w', encoding='utf-8').write(out)
        print(f"{rel:<34} {src.count(chr(10))+1:>5} {len(todo):>8}")
        all_todo += [(rel, t) for t in todo]
    print('-' * 60)
    print(f"合计 {len(files)} 个模板，待人工处理项 {len(all_todo)} 条\n")
    from collections import Counter
    cat = Counter(re.sub(r'\d+', 'N', t).split('→')[0].strip() for _, t in all_todo)
    print("按类型聚合：")
    for k, v in cat.most_common():
        print(f"  {v:>3}×  {k}")

if __name__ == '__main__':
    main()
