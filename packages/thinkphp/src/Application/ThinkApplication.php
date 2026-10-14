<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Application;

use Aimanong\Contracts\ApplicationResolver;
use think\facade\Config;
use think\facade\Request;

/**
 * 多应用（多后台）解析器 —— ThinkPHP 版。
 *
 * ## 与 Laravel 侧的关系
 *
 * 两边实现的是**同一个契约**（`Aimanong\Contracts\ApplicationResolver`），
 * 语义必须一致，差别只在「怎么拿当前请求路径」：
 *
 *   Laravel   `Illuminate\Http\Request::path()`
 *   ThinkPHP  `think\Request::pathinfo()`
 *
 * ## 权威是「请求路径」，不是配置
 *
 * Laravel 侧的 `ApplicationContext` 有一段注释，记录了**踩过 4 次**的坑：
 * 切换应用会改写全局 config，于是任何读 config 的代码都可能拿到别的应用的值
 * （URL 跳错、session key 不一致导致无限重定向、CSRF 419、LOGO 指错）。
 *
 * 结论是「**不信任 config，只信任当前请求路径**」。这边照做。
 *
 * ## 与 Laravel 的第一版不同的地方（是 Laravel 那边修过之后的形态）
 *
 * 1. **CLI 下不读进程级残留** —— 没有请求就用配置里的第一个应用，
 *    不读任何「上一次请求切换过」的状态
 * 2. **HTTP 下以路径推断为准** —— 而不是「某处 switch 过」的残留值
 *
 * 这两条在 Laravel 上都是实测踩出来的 bug（CLI 里 current 一直是别的应用、
 * HTTP 下恒为默认应用导致非默认后台菜单全空）。
 */
final class ThinkApplication implements ApplicationResolver
{
    /** 未配置多应用时的默认应用名（与 Laravel 侧的 SingleApplication 对齐）。 */
    public const DEFAULT = 'default';

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $applications = [];

    protected ?string $resolved = null;

    public function __construct()
    {
        try {
            $config = Config::get('aimanong.applications', []);
        } catch (\Throwable) {
            $config = [];
        }

        $this->applications = is_array($config) ? $config : [];
    }

    public function current(): string
    {
        return $this->resolved ??= $this->doResolve();
    }

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

    public function prefix(string $name): string
    {
        $prefix = $this->configOf($name, 'route')['prefix'] ?? $name;

        return is_string($prefix) ? trim($prefix, '/') : $name;
    }

    public function guard(string $name): string
    {
        $guard = $this->configOf($name, 'auth')['guard'] ?? 'admin';

        return is_string($guard) && $guard !== '' ? $guard : 'admin';
    }

    /**
     * @return class-string
     */
    public function model(string $name): string
    {
        $model = $this->configOf($name, 'auth')['model'] ?? null;

        /** @var class-string $model */
        $model = is_string($model) && $model !== '' ? $model : \stdClass::class;

        return $model;
    }

    public function title(string $name): string
    {
        $title = $this->applications[$name]['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : $name;
    }

    /**
     * 某应用的原始配置。
     *
     * @return array<string, mixed>
     */
    public function appConfig(string $name): array
    {
        return $this->applications[$name] ?? [];
    }

    /**
     * 清除解析缓存（测试或多应用切换后调用）。
     */
    public function flush(): void
    {
        $this->resolved = null;
    }

    /**
     * 核心解析：**以请求路径为准**。
     */
    protected function doResolve(): string
    {
        if (! $this->enabled()) {
            return self::DEFAULT;
        }

        $path = $this->requestPath();

        // 无请求上下文（CLI / 队列）→ 配置里的第一个应用
        if ($path === null) {
            return $this->firstApp();
        }

        $path = trim($path, '/');

        /*
         * 长前缀优先。
         *
         * 否则 `admin` 会先匹配上 `admin-x` 这类前缀，认错应用。
         * （Laravel 侧同样处理，两边行为要一致。）
         */
        $names = $this->names();
        usort($names, fn (string $a, string $b): int => strlen($this->prefix($b)) <=> strlen($this->prefix($a)));

        foreach ($names as $name) {
            $p = $this->prefix($name);

            if ($p === '') {
                continue;
            }

            if ($path === $p || str_starts_with($path, $p.'/')) {
                return $name;
            }
        }

        return $this->firstApp();
    }

    /**
     * 当前请求路径。无请求上下文返回 null。
     */
    protected function requestPath(): ?string
    {
        try {
            /*
             * CLI / 队列：没有 HTTP 请求，不能去猜路径。
             * 这里必须显式判断 —— `Request::pathinfo()` 在 CLI 下
             * 可能返回残留值或抛异常，两种都会让 current() 认错后台。
             */
            if (app()->runningInConsole()) {
                return null;
            }

            $path = Request::pathinfo();

            return $path !== '' ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function firstApp(): string
    {
        $names = $this->names();

        return $names[0] ?? self::DEFAULT;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configOf(string $name, string $section): array
    {
        $value = $this->applications[$name][$section] ?? [];

        return is_array($value) ? $value : [];
    }
}
