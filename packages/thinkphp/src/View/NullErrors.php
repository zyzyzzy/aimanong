<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\View;

/**
 * 校验错误包的空实现。
 *
 * ⚠️ **不能是 final** —— `ViewErrors` 继承它来表示「有一条错误」。
 * （第一版写成 final，登录失败时当场 `PHP Fatal error`。）
 *
 * Laravel 的模板用 `$errors->any()` / `$errors->first()`；
 * TP 侧还没有校验错误包，所以给一个**具名空对象** ——
 * 界面显示「没有错误」，而不是因为 `$errors` 未定义整页炸掉。
 */
class NullErrors
{
    public function any(): bool
    {
        return false;
    }

    public function first(string $key = '', string $format = ':message'): string
    {
        return '';
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function toArray(): array
    {
        return [];
    }
}
