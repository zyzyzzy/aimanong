<?php

declare(strict_types=1);

namespace Aimanong\Application;

use Aimanong\Aimanong;
use Illuminate\Http\Request;

/**
 * 应用上下文 —— **推断当前后台的唯一入口**。
 *
 * ## 为什么需要它
 *
 * 多应用切换（`ApplicationManager::switch()`）会**改写全局 config**
 * （`aimanong.route.prefix` / `aimanong.auth.guard` 等）。
 * 这导致任何直接读 config 的代码都可能拿到**别的应用**的值。
 *
 * 这个坑在五轮 AI 实测与开发中反复出现，一共踩了 4 次：
 *
 *   1. `Aimanong::url()`      → admin 的跳转跑到 merchant
 *   2. `Aimanong::guard()`    → session key 不一致 → 无限重定向
 *   3. `Middleware\Session`   → cookie path 写错 → CSRF 419
 *   4. `Support\Asset::url()` → LOGO 指向 /merchant/assets/...
 *
 * ## 解决方式
 *
 * **不信任 config，只信任当前请求路径。**
 * 请求从哪个后台进来，就用哪个应用的配置。
 * 无请求上下文（如 CLI）时才回退到 config。
 *
 * 所有需要「当前应用的前缀 / guard / 模型」的地方，
 * 一律通过本类获取，不得直接读 config。
 */
class ApplicationContext
{
    /**
     * 缓存的解析结果（同一请求内不变）。
     *
     * @var array{app: string, prefix: string, guard: string}|null
     */
    protected ?array $resolved = null;

    /**
     * 当前请求所属的应用名。
     */
    public function app(): string
    {
        return $this->resolve()['app'];
    }

    /**
     * 当前请求所属后台的路由前缀。
     */
    public function prefix(): string
    {
        return $this->resolve()['prefix'];
    }

    /**
     * 当前请求所属后台的 auth guard 名。
     */
    public function guard(): string
    {
        return $this->resolve()['guard'];
    }

    /**
     * 当前后台的 session/路由域名。
     */
    public function domain(): ?string
    {
        // config() 内部会访问容器，无容器时必须整体兜住
        try {
            $config = $this->config();

            $domain = $config['route']['domain'] ?? null;

            if (is_string($domain) && $domain !== '') {
                return $domain;
            }

            $fallback = config('aimanong.route.domain');
        } catch (\Throwable) {
            return null;
        }

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * 当前应用的完整配置（未匹配到应用时返回 null）。
     *
     * @return array<string, mixed>|null
     */
    public function config(): ?array
    {
        $name = $this->app();

        /** @var ApplicationManager $apps */
        $apps = app('aimanong.application');

        return $apps->config($name);
    }

    /**
     * 清除缓存（测试或多应用切换后调用）。
     */
    public function flush(): void
    {
        $this->resolved = null;
    }

    /**
     * 核心解析逻辑。
     *
     * @return array{app: string, prefix: string, guard: string}
     */
    protected function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = $this->doResolve();
    }

    /**
     * @return array{app: string, prefix: string, guard: string}
     */
    protected function doResolve(): array
    {
        /*
         * config 兜底值（单后台模式 / CLI 场景）。
         *
         * 用 try/catch 包住：极早期引导或单元测试环境可能没有容器，
         * 此时 config() helper 会抛异常 —— 不能让上下文推断本身崩掉，
         * 否则整个框架都无法启动。
         */
        /*
         * ⚠️ fallback **不能**读 `config('aimanong.route.prefix')`。
         *
         * `ApplicationManager::applyConfig()` 会把当前应用的配置
         * **合并进全局 config**（改 `route.prefix` / `auth.guard`）。
         * 那是进程内的一次性副作用，不会自动还原 ——
         * 于是下一次（尤其 CLI 命令）再读 `route.prefix`，
         * 拿到的是**上一次请求的应用**，fallback 就认错了后台。
         *
         * 实测踩到：CLI 里 `route.prefix` 变成 'agent'，
         * 导致菜单过滤错后台、权限 slug 加错前缀。
         *
         * 正确做法：fallback 用 `applications` 配置里**第一个**应用
         * （配置顺序即优先级，用户在 config 里把主后台放第一个）。
         */
        try {
            /** @var ApplicationManager $apps */
            $apps = app('aimanong.application');
            $names = $apps->names();
        } catch (\Throwable) {
            $names = [];
        }

        if ($names !== []) {
            $first = $names[0];
            $fallback = [
                'app' => $first,
                'prefix' => trim((string) config("aimanong.applications.{$first}.route.prefix", $first), '/'),
                'guard' => (string) config("aimanong.applications.{$first}.auth.guard", 'admin'),
            ];
        } else {
            try {
                $defaultPrefix = config('aimanong.route.prefix');
                $defaultGuard = config('aimanong.auth.guard');
            } catch (\Throwable) {
                $defaultPrefix = null;
                $defaultGuard = null;
            }

            $defaultPrefix = is_string($defaultPrefix) ? trim($defaultPrefix, '/') : 'admin';
            $defaultGuard = is_string($defaultGuard) ? $defaultGuard : 'admin';

            $fallback = [
                'app' => $defaultPrefix,
                'prefix' => $defaultPrefix,
                'guard' => $defaultGuard,
            ];
        }

        try {
            /** @var ApplicationManager $apps */
            $apps = app('aimanong.application');

            if (! $apps->enabled()) {
                return $fallback;
            }

            $request = $this->currentRequest();

            if ($request === null) {
                return $fallback;
            }

            $path = trim($request->path(), '/');
            $names = $apps->names();

            /*
             * 长前缀优先匹配。
             * 否则 `admin` 会先匹配上 `admin-x` 这类前缀，导致认错应用。
             */
            usort($names, fn (string $a, string $b): int => strlen($apps->prefix($b)) <=> strlen($apps->prefix($a)));

            foreach ($names as $name) {
                $p = $apps->prefix($name);

                if ($path === $p || str_starts_with($path, $p.'/')) {
                    return [
                        'app' => $name,
                        'prefix' => $p,
                        'guard' => $apps->guard($name),
                    ];
                }
            }
        } catch (\Throwable) {
            // 容器未就绪（如极早期引导）时回退
        }

        return $fallback;
    }

    /**
     * 取当前请求。无请求上下文时返回 null。
     */
    protected function currentRequest(): ?Request
    {
        try {
            $request = app('request');

            return $request instanceof Request ? $request : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
