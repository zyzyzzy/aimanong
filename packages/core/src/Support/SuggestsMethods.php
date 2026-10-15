<?php

declare(strict_types=1);

namespace Aimanong\Support;

use BadMethodCallException;

/**
 * 给声明层 API 类挂上「调错方法名时给出可自愈报错」的行为。
 *
 * 用法：在类里 `use SuggestsMethods;`。生成逻辑见 {@see MethodSuggester}。
 *
 * 适用的类是**面向声明**的那些（`Field` / `Column` / `Grid` / `Show` / `Tree`）——
 * 它们的方法名就是 AI 要猜的 API 表面。内部工具类不需要。
 */
trait SuggestsMethods
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        throw new BadMethodCallException(MethodSuggester::message(static::class, $method));
    }
}
