<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Application\ApplicationContext;
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
        $guard = app('auth')->guard(self::context()->guard());

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
        // 统一走 context 推断，避免多应用 config 污染
        $prefix = self::context()->prefix();

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
     * 应用上下文 —— 推断当前后台的唯一入口。
     *
     * 所有需要「当前应用的前缀 / guard」的地方都应通过它获取，
     * 而不是直接读 config（多应用下 config 会被 switch() 污染）。
     */
    public static function context(): ApplicationContext
    {
        /** @var ApplicationContext $c */
        $c = app('aimanong.context');

        return $c;
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
     * 版本号 —— **单一来源**。
     *
     * 从 composer.json 读取，避免多处硬编码导致版本不一致。
     * 修改版本请只改 composer.json 的 version 字段。
     */
    public static function version(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        try {
            $path = __DIR__.'/../composer.json';

            if (is_file($path)) {
                /** @var mixed $decoded */
                $decoded = json_decode((string) file_get_contents($path), true);

                if (is_array($decoded)
                    && isset($decoded['version'])
                    && is_string($decoded['version'])
                    && $decoded['version'] !== '') {
                    return $cached = $decoded['version'];
                }
            }
        } catch (\Throwable) {
            // 读不到时回退
        }

        return $cached = '1.0.0';
    }
}
