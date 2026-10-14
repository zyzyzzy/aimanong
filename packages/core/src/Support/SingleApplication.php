<?php

declare(strict_types=1);

namespace Aimanong\Support;

use Aimanong\Contracts\ApplicationResolver;

/**
 * 单应用（只有一个后台）时的默认解析器。
 *
 * ## 为什么需要它
 *
 * 让所有「按应用过滤」的代码**无需分叉**：没注册真实解析器时用它兜底，
 * `current()` 恒返回 `'default'`，`enabled()` 恒 false。
 *
 * 于是 MenuRegistry / ResourceController 里可以只写：
 *
 * ```php
 * $app = Drivers::application()->current();
 * ```
 *
 * 而不用先判断「多应用有没有开」。单应用是**多应用的一个特例** ——
 * 只有一个应用时，任何 Resource 都属于它。
 */
final class SingleApplication implements ApplicationResolver
{
    public const NAME = 'default';

    public function current(): string
    {
        return self::NAME;
    }

    public function enabled(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return [self::NAME];
    }

    public function prefix(string $name): string
    {
        return '';
    }

    public function guard(string $name): string
    {
        return 'admin';
    }

    /**
     * @return class-string
     */
    public function model(string $name): string
    {
        // 单应用下没有真实模型概念，返回一个占位类名供类型签名使用
        return \stdClass::class;
    }

    public function title(string $name): string
    {
        return '';
    }
}
