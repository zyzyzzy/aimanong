<?php

declare(strict_types=1);

namespace Aimanong\Application;

use Aimanong\Aimanong;
use Aimanong\Contracts\ApplicationResolver;
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
class ApplicationManager implements ApplicationResolver
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

    /**
     * 防止 current() → context()->app() → … → current() 的递归。
     */
    protected static bool $resolving = false;

    public function current(): string
    {
        if (! $this->hasRequestContext()) {
            /*
             * CLI / 队列 / 测试：用实例状态。
             *
             * ⚠️ 这里**绝不能**读 Context —— 它是进程级单例，不随
             * 「命令结束」清零，上一次请求 switch 过的应用会跨命令残留。
             */
            return $this->current;
        }

        /*
         * HTTP 请求：当前应用以**请求路径**为准，即 ApplicationContext。
         *
         * ⚠️ 这是多应用能不能工作的关键。
         *
         * `ApplicationContext` 才是权威（它的注释写着「不信任 config、
         * 只信任当前请求路径」），但 `ApplicationManager::current()`
         * 原先只读 Context / 实例状态 —— 而**没有任何一处会在请求期写它**
         * （`switch()` 只在路由注册时按应用各调一次，且注册完就恢复了）。
         *
         * 后果：HTTP 下 current() 恒为默认应用（admin），于是
         *   · 代理商后台的权限判定去查 `admin.*` 节点 → 全拒 → 菜单空白
         *   · 用户后台同理
         * 而 `/admin` 恰好是对的，所以**只有非默认后台会坏**，
         * 且不报错（只是「什么都没有」）。
         *
         * 实测：CLI 下 agent 有 26 个菜单项，HTTP 下 0 个 —— 就是这个原因。
         */
        if (self::$resolving) {
            return $this->current;
        }

        self::$resolving = true;

        try {
            $app = app('aimanong.context')->app();

            if (is_string($app) && $app !== '' && $this->has($app)) {
                return $app;
            }
        } catch (\Throwable) {
            // 容器未就绪 → 回退实例状态
        } finally {
            self::$resolving = false;
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

            /*
             * ⚠️ **只在有 HTTP 请求时才写 Context**。
             *
             * Laravel 的 Context 是**进程级**单例，在 CLI / 队列里不会随
             * 「请求结束」清零。若在 CLI 下也写，下一次命令（或同一个
             * worker 里的下一个任务）会读到上一次残留的应用名 ——
             * 表现是 `Drivers::application()->current()` 莫名变成别的应用，
             * 于是权限 slug 加错前缀、菜单过滤错后台。
             *
             * 实测踩到：CLI 里跑菜单检查，current 一直是 'agent'
             * （上一次 HTTP 请求 switch 过去的）。
             */
            if ($this->hasRequestContext()) {
                try {
                    Context::add(self::CONTEXT_KEY, $name);
                } catch (\Throwable) {
                    // 无容器时仅用实例状态
                }
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
     * 当前是否处于 HTTP 请求上下文中。
     *
     * CLI / 队列 / 测试里没有请求 —— 这时绝不能把应用名写进
     * 进程级的 Context（见 switch() 的说明）。
     */
    protected function hasRequestContext(): bool
    {
        try {
            return app()->runningInConsole() === false
                && app('request') !== null;
        } catch (\Throwable) {
            return false;
        }
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
     * 某应用的用户模型类名。
     *
     * @return class-string
     */
    public function model(string $name): string
    {
        $config = $this->config($name);
        $model = $config['auth']['model'] ?? null;

        /** @var class-string $model */
        $model = is_string($model) && $model !== '' ? $model : \stdClass::class;

        return $model;
    }

    /**
     * 某应用的显示标题（后台标题、菜单分组用）。
     */
    public function title(string $name): string
    {
        $config = $this->config($name);
        $title = $config['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : $name;
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
