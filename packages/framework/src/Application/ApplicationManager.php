<?php

declare(strict_types=1);

namespace Aimanong\Application;

use Aimanong\Aimanong;
use Illuminate\Support\Facades\Context;

/**
 * 多应用（多后台）管理。
 *
 * 场景：一个 Laravel 项目里跑多个互相隔离的后台，
 * 例如 `/admin`（运营后台）与 `/merchant`（商家后台），
 * 各自有独立的用户体系与菜单。
 *
 * 设计要点：
 *   1. 应用配置在 config/aimanong.php 的 applications 中声明
 *   2. 当前应用通过 Laravel 12 的 Context 做请求级隔离
 *      （比全局单例安全：并发请求不会串味）
 *   3. 每个应用有独立的 auth guard 与 providers
 */
class ApplicationManager
{
    public const CONTEXT_KEY = 'aimanong.application';

    public const DEFAULT = 'admin';

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $applications = [];

    protected string $current = self::DEFAULT;

    public function __construct()
    {
        $config = config('aimanong.applications', []);
        $this->applications = is_array($config) ? $config : [];
    }

    /**
     * 全部已配置的应用。
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->applications;
    }

    public function has(string $name): bool
    {
        return isset($this->applications[$name]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function config(string $name): ?array
    {
        return $this->applications[$name] ?? null;
    }

    public function current(): string
    {
        // 优先从 Context 取（请求级隔离）
        try {
            $ctx = Context::get(self::CONTEXT_KEY);

            if (is_string($ctx) && $ctx !== '') {
                return $ctx;
            }
        } catch (\Throwable) {
            // 无容器时回退到实例状态
        }

        return $this->current;
    }

    /**
     * 切换当前应用。
     *
     * 会同时写入 Context 与实例状态，并把该应用的配置
     * 合并进 admin 配置，使后续代码读到正确值。
     */
    public function switch(string $name): static
    {
        if ($this->has($name)) {
            $this->current = $name;

            try {
                Context::add(self::CONTEXT_KEY, $name);
            } catch (\Throwable) {
                // 无容器时仅用实例状态
            }

            $this->applyConfig($name);
        }

        return $this;
    }

    /**
     * 把应用配置合并进 admin 配置。
     */
    protected function applyConfig(string $name): void
    {
        $config = $this->config($name);

        if ($config === null) {
            return;
        }

        $admin = config('aimanong', []);

        if (! is_array($admin)) {
            return;
        }

        // 应用级配置覆盖全局配置
        foreach (['route', 'auth'] as $key) {
            if (isset($config[$key]) && is_array($config[$key])) {
                $admin[$key] = array_merge($admin[$key] ?? [], $config[$key]);
            }
        }

        config(['aimanong' => $admin]);
    }

    /**
     * 当前应用的路由前缀。
     */
    public function prefix(?string $name = null): string
    {
        $name ??= $this->current();
        $config = $this->config($name);

        $prefix = $config['route']['prefix'] ?? $name;

        return is_string($prefix) ? trim($prefix, '/') : $name;
    }

    /**
     * 当前应用的 guard 名。
     */
    public function guard(?string $name = null): string
    {
        $name ??= $this->current();
        $config = $this->config($name);

        $guard = $config['auth']['guard'] ?? 'admin';

        return is_string($guard) ? $guard : 'admin';
    }

    /**
     * 是否启用了多应用。
     */
    public function enabled(): bool
    {
        return $this->applications !== [];
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->applications);
    }

    /**
     * 应用清单（供自省与后台展示）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->applications as $name => $config) {
            $out[] = [
                'name' => $name,
                'title' => $config['title'] ?? $name,
                'prefix' => $this->prefix($name),
                'guard' => $this->guard($name),
                'model' => $config['auth']['model'] ?? null,
                'is_current' => $name === $this->current(),
            ];
        }

        return $out;
    }
}
