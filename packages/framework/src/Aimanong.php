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
 * AI-First 约定：方法签名唯一、无重载、无魔法。
 */
class Aimanong
{
    protected static bool $booted = false;

    /**
     * 获取后台 guard。
     */
    public static function guard(): AdminGuard
    {
        /** @var AdminGuard $guard */
        $guard = app('auth')->guard(config('aimanong.auth.guard', 'admin'));

        return $guard;
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
        $prefix = config('aimanong.route.prefix');
        $prefix = is_string($prefix) ? trim($prefix, '/') : 'admin';

        return rtrim($prefix, '/').'/'.ltrim($path, '/');
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
