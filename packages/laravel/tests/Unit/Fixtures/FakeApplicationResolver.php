<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Contracts\ApplicationResolver;
use Illuminate\Foundation\Auth\User;

/**
 * 测试用的应用解析器 —— 当前应用由外部指定，便于逐个后台断言。
 */
class FakeApplicationResolver implements ApplicationResolver
{
    /**
     * @param  array<string, array<string, mixed>>  $apps
     */
    public function __construct(
        private string $current = 'admin',
        private array $apps = [
            'admin' => ['prefix' => 'admin', 'guard' => 'admin', 'model' => User::class, 'title' => '总管理后台'],
            'user' => ['prefix' => 'user', 'guard' => 'user', 'model' => User::class, 'title' => '用户后台'],
            'agent' => ['prefix' => 'agent', 'guard' => 'agent', 'model' => User::class, 'title' => '代理商后台'],
        ],
    ) {}

    public function withCurrent(string $app): self
    {
        $clone = clone $this;
        $clone->current = $app;

        return $clone;
    }

    public function current(): string
    {
        return $this->current;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function names(): array
    {
        return array_keys($this->apps);
    }

    public function prefix(string $name): string
    {
        return (string) ($this->apps[$name]['prefix'] ?? '');
    }

    public function guard(string $name): string
    {
        return (string) ($this->apps[$name]['guard'] ?? 'admin');
    }

    public function model(string $name): string
    {
        return (string) ($this->apps[$name]['model'] ?? User::class);
    }

    public function title(string $name): string
    {
        return (string) ($this->apps[$name]['title'] ?? '');
    }
}
