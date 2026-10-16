<?php

declare(strict_types=1);

namespace Aimanong\Support;

/**
 * core 自己的字符串工具 —— **不依赖任何框架助手函数**。
 *
 * ## 为什么必须有这个类
 *
 * `packages/core` 是发布到 Packagist 的**独立包**，README 里明确说它是
 * 「框架无关内核」。但在此之前它有 5 处调用了 `class_basename()` ——
 * 那是 **Laravel 的全局助手**（定义在 `illuminate/support/helpers.php`）。
 *
 * 之所以一直没炸：ThinkPHP 通过 `topthink/think-helper` **也**定义了
 * 同名函数。也就是说「恰好两边都有」，而不是「core 自己能站住」。
 *
 * 真出事的情形：
 *   · 有人把 `aimanong/core` 单独装进一个非 Laravel/TP 的项目
 *   · 依赖顺序变化导致助手文件未被加载
 *   → `Call to undefined function class_basename()`，致命错误
 *
 * ## 为什么纯度门禁没拦住
 *
 * `scripts/check-core-purity.sh` 原先只查 `use Illuminate\...` 与
 * 全限定名 —— **查不到全局助手函数**。已补上这一类（并做了反例验证）。
 *
 * 教训：「框架无关」不能只查 import。全局函数是**隐式**依赖 ——
 * 没有 use、没有 FQN，但一样把 core 钉在某个框架上。
 */
final class Str
{
    /**
     * 取类名（不含命名空间），等价于 Laravel / TP 的 `class_basename()`。
     *
     * `class_basename('App\\Models\\User')` → `'User'`
     * `class_basename('User')` → `'User'`
     */
    public static function classBasename(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
