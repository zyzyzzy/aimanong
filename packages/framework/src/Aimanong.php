<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Application\ApplicationManager;
use Aimanong\Auth\AdminGuard;
use Aimanong\Extend\ExtensionManager;
use Aimanong\Support\Asset;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * 框架主入口。
 *
 * Aimanong 约定：方法签名唯一、无重载、无魔法。
 */
class Aimanong
{
    protected static bool $booted = false;

    /**
     * 获取当前后台的 guard。
     *
     * 多应用下 config('aimanong.auth.guard') 会被最后一次 switch() 覆盖，
     * 直接读它会导致 admin 请求拿到 merchant 的 guard
     * （session key 不一致 → 登录态丢失 → 无限重定向）。
     *
     * 因此与 url() 同理：从当前请求路径推断所属应用，再取该应用的 guard。
     */
    public static function guard(): AdminGuard
    {
        /** @var AdminGuard $guard */
        $guard = app('auth')->guard(self::currentGuard());

        return $guard;
    }

    /**
     * 推断当前请求所属后台的 guard 名。
     */
    protected static function currentGuard(): string
    {
        $fallback = config('aimanong.auth.guard', 'admin');
        $fallback = is_string($fallback) ? $fallback : 'admin';

        try {
            /** @var ApplicationManager $apps */
            $apps = app('aimanong.application');

            if (! $apps->enabled()) {
                return $fallback;
            }

            $path = trim(request()->path(), '/');
            $names = $apps->names();
            usort($names, fn ($a, $b): int => strlen($b) <=> strlen($a));

            foreach ($names as $name) {
                $p = $apps->prefix($name);

                if ($path === $p || str_starts_with($path, $p.'/')) {
                    return $apps->guard($name);
                }
            }
        } catch (\Throwable) {
            // 无请求上下文时回退
        }

        return $fallback;
    }

    /**
     * 当前登录管理员。
     */
    public static function user(): ?Authenticatable
    {
        return self::guard()->user();
    }

    /**
     * 生成后台 URL。签名唯一，仅接收路径。
     */
    public static function url(string $path = ''): string
    {
        /*
         * 多应用下 config('aimanong.route.prefix') 会被最后一次
         * switch() 覆盖 —— 用它生成 URL 会导致 admin 后台的登录跳转
         * 跑到 merchant 去。
         *
         * 因此优先从**当前请求路径**推断前缀：请求从哪个后台进来，
         * 跳转就回到哪个后台。
         */
        $prefix = self::currentPrefix();

        return rtrim($prefix, '/').'/'.ltrim($path, '/');
    }

    /**
     * 推断当前后台的路由前缀。
     *
     * 优先匹配已配置的应用前缀（按长度倒序，避免 admin 匹配到 admin-x），
     * 其次回退到 config 值。
     */
    protected static function currentPrefix(): string
    {
        $fallback = config('aimanong.route.prefix');
        $fallback = is_string($fallback) ? trim($fallback, '/') : 'admin';

        try {
            $path = trim(request()->path(), '/');

            /** @var ApplicationManager $apps */
            $apps = app('aimanong.application');

            if ($apps->enabled()) {
                $names = $apps->names();
                usort($names, fn ($a, $b): int => strlen($b) <=> strlen($a));

                foreach ($names as $name) {
                    $p = $apps->prefix($name);

                    if ($path === $p || str_starts_with($path, $p.'/')) {
                        return $p;
                    }
                }
            }
        } catch (\Throwable) {
            // 无请求上下文（如命令行）时回退
        }

        return $fallback;
    }

    /**
     * 资源登记器。
     */
    public static function asset(): Asset
    {
        /** @var Asset $asset */
        $asset = app('aimanong.asset');

        return $asset;
    }

    /**
     * 多应用管理器。
     */
    public static function application(): ApplicationManager
    {
        /** @var ApplicationManager $m */
        $m = app('aimanong.application');

        return $m;
    }

    /**
     * 扩展管理器。
     */
    public static function extensions(): ExtensionManager
    {
        /** @var ExtensionManager $m */
        $m = app('aimanong.extensions');

        return $m;
    }

    /**
     * Resource 注册表。
     */
    public static function registry(): Registry
    {
        /** @var Registry $registry */
        $registry = app(Registry::class);

        return $registry;
    }

    /**
     * 框架引导（幂等）。
     */
    public static function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
    }

    /**
     * 版本号。
     */
    public static function version(): string
    {
        return '0.1.0';
    }
}
