<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\View;

/**
 * 带一条消息的错误包。
 *
 * 模板用 `$errors->any()` / `$errors->first()`（Laravel 的写法），
 * TP 侧没有 ViewErrorBag，所以这里提供一个最小实现。
 *
 * 分成两个类（而非匿名类继承 NullErrors）的原因很实际：
 * `NullErrors` 是 final —— 匿名类继承它会直接 `PHP Fatal error`。
 * 具名类也更好读。
 */
final class ViewErrors extends NullErrors
{
    public function __construct(private readonly string $message = '') {}

    public function any(): bool
    {
        return $this->message !== '';
    }

    public function first(string $key = '', string $format = ':message'): string
    {
        return $this->message;
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->message === '' ? [] : [$this->message];
    }
}
