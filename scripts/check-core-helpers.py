#!/usr/bin/env python3
"""
core 纯度检查的第三类：**框架的全局助手函数**。

## 为什么单独一个脚本

`check-core-purity.sh` 原先只查 `use Illuminate\...` 与全限定名 ——
都查不到**全局函数**。而全局函数是**隐式**依赖：没有 use、没有 FQN，
但一样把 core 钉在某个框架上。

真实漏洞：core 里有 5 处调了 `class_basename()` —— Laravel 的助手。
之所以没炸，是因为 ThinkPHP 通过 `topthink/think-helper` **也**定义了
同名函数 ——「恰好两边都有」，而不是「core 自己能站住」。

但 `aimanong/core` 是**独立发布**的包，README 说它是「框架无关内核」。
谁把它单独装进一个非 Laravel/TP 的项目，就是
`Call to undefined function class_basename()`。

## 判定规则

⚠️ 必须排除方法调用：`->value(` / `::value(` / `$this->value(` 都不是
全局函数。误报会让这条门禁很快被人忽略 —— 那比没有门禁更糟。

用法：
    python3 scripts/check-core-helpers.py <core-src-dir>

输出：每行 `<file>:<line>:<fn>`；无违规则无输出。
退出码：0 = 干净，1 = 有违规。
"""
import os
import re
import sys

# 框架（Laravel / ThinkPHP）提供的全局助手函数。
# 只列**确定是框架提供、core 不该依赖**的那些。
BANNED = [
    'class_basename', 'str_slug', 'str_random', 'str_contains_any',
    'array_wrap', 'array_flatten', 'data_get', 'data_set', 'data_fill',
    'optional', 'collect', 'tap', 'head', 'last', 'blank', 'filled',
    'app', 'config', 'env', 'response', 'request', 'route', 'view',
    'abort', 'redirect', 'session', 'cache', 'url', 'asset', 'now',
    'today', 'retry', 'transform', 'rescue', 'throw_if', 'throw_unless',
    'dispatch', 'event', 'logger', 'report', 'bcrypt', 'encrypt', 'decrypt',
    'validate', 'auth', 'gate', 'policy', 'method_field', 'csrf_field',
]

# 前面**不能**是这些字符，否则是方法调用 / 静态调用 / 变量
#   ->foo(   ::foo(   $foo(   \foo(（全限定名也算另一种情况）
PATTERN = re.compile(
    r'(?<![\w$>:\\.])\b(' + '|'.join(BANNED) + r')\s*\('
)

# 字符串字面量（保留引号位置，内容清空）——
# 用来排除「示例文案里提到某个助手」这种误报。
STRINGS = re.compile(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"")


def main() -> int:
    if len(sys.argv) < 2:
        print('用法: check-core-helpers.py <core-src-dir>', file=sys.stderr)
        return 2

    core = sys.argv[1]
    hits = []

    for root, _dirs, files in os.walk(core):
        for name in files:
            if not name.endswith('.php'):
                continue

            path = os.path.join(root, name)

            try:
                lines = open(path, encoding='utf-8').read().split('\n')
            except OSError:
                continue

            for i, line in enumerate(lines, 1):
                stripped = line.lstrip()

                # ① 跳过注释行（文档块内 / 行注释 / 块注释开头）
                if stripped.startswith(('*', '//', '/*', '#')):
                    continue

                # ② 跳过**方法声明** —— `public static function url(...)`
                #    名字与某个助手同名不代表在调用它。
                if 'function ' in line:
                    continue

                # ③ 去掉字符串字面量的内容再匹配。
                #
                #    `'config' => "config('aimanong.x')"` 这种行里，
                #    `config(` 只是**字符串内容**（给 AI 看的用法示例），
                #    不是调用。不去掉会造成大量误报 ——
                #    而误报会让门禁很快被人忽略，那比没有门禁更糟。
                code = STRINGS.sub('""', line)

                m = PATTERN.search(code)

                if m:
                    hits.append(f'{path}:{i}:{m.group(1)}')

    if hits:
        print('\n'.join(hits))
        return 1

    return 0


if __name__ == '__main__':
    sys.exit(main())
