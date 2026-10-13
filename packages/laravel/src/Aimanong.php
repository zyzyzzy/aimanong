<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Application\ApplicationContext;
use Aimanong\Application\ApplicationManager;
use Aimanong\Auth\AdminGuard;
use Aimanong\Extend\ExtensionManager;
use Aimanong\Support\Asset;
use Composer\InstalledVersions;
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
     * 品牌名（控制台标题与页脚用）。
     *
     * 可在 config/aimanong.php 里改 ui.brand 换成自己的系统名 ——
     * 基于 Aimanong 开发的项目通常不希望页脚写着框架名。
     */
    public static function brand(): string
    {
        try {
            $brand = config('aimanong.ui.brand');
        } catch (\Throwable) {
            $brand = null;
        }

        return is_string($brand) && $brand !== '' ? $brand : 'Aimanong';
    }

    /**
     * 版本号 —— **单一来源**：`aimanong/framework` 的 composer.json。
     *
     * ## 拆包之后，「版本号」指的是什么
     *
     * 一次安装会引入三个包，各有自己的版本：
     *
     *     aimanong/framework  1.9.0   ← 用户装的、对外的**产品版本**
     *     aimanong/laravel    1.0.0   ← 内部驱动包
     *     aimanong/core       1.0.0   ← 内部内核包
     *
     * 后台界面顶上显示的、文档里写的、README 徽章上的，都应该是
     * **产品版本**（framework），不是内部包的版本 —— 用户装的是 framework，
     * 他关心的是那个号码。内部包怎么升版不该影响他看到的数字。
     *
     * ## 为什么用相对路径而不是 Composer 的 InstalledVersions
     *
     * 下面第一个候选路径在**两种布局下都成立**：
     *
     *   仓库内开发：  packages/laravel/src/../../framework/composer.json
     *   装成依赖：    vendor/aimanong/laravel/src/../../framework/composer.json
     *
     * 两者都指向 `aimanong/framework/composer.json`。这比走 Composer API
     * 少一层依赖，也能在「只装了 laravel 没装 framework」时自然回退。
     */
    public static function version(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        /*
         * ① 仓库内开发 → 直接读产品包的 composer.json。
         *
         *    驱动包自己跑测试时，它就是 root 包，Composer 的 InstalledVersions
         *    里既没有 framework 也没有 laravel（root 不算「已安装依赖」），
         *    所以必须靠路径。
         *
         *    路径在**两种布局下都成立**：
         *      仓库内：  packages/laravel/src/../../framework/composer.json
         *      装成依赖：vendor/aimanong/laravel/src/../../framework/composer.json
         *    后者在 metapackage 场景下不存在（Composer 不建目录），
         *    于是自然落到下面的 ②。
         */
        $candidates = [
            __DIR__.'/../../framework/composer.json',
            __DIR__.'/../composer.json',
        ];

        foreach ($candidates as $path) {
            if (! is_file($path)) {
                continue;
            }

            try {
                /** @var mixed $decoded */
                $decoded = json_decode((string) file_get_contents($path), true);
            } catch (\Throwable) {
                continue;
            }

            if (is_array($decoded)
                && isset($decoded['version'])
                && is_string($decoded['version'])
                && $decoded['version'] !== '') {
                return $cached = $decoded['version'];
            }
        }

        /*
         * ② 装在应用里 → 走 Composer 的运行时 API。
         *
         * ⚠️ 这里**不能**只靠文件路径：`aimanong/framework` 是 metapackage
         * （type: metapackage），Composer 不为它建目录，
         * `vendor/aimanong/framework/composer.json` 根本不存在 ——
         * 但 Composer 把它记进了 `installed.php`，所以 API 拿得到。
         *
         * 实测踩到过：只按文件路径找 framework，会在真实安装里静默回退成
         * laravel 的 1.0.0，后台界面就显示错的版本号。
         */
        if (class_exists(InstalledVersions::class)) {
            foreach (['aimanong/framework', 'aimanong/laravel'] as $pkg) {
                if (! InstalledVersions::isInstalled($pkg)) {
                    continue;
                }

                $ver = InstalledVersions::getPrettyVersion($pkg);

                if (is_string($ver) && $ver !== '') {
                    return $cached = $ver;
                }
            }
        }

        return $cached = '1.0.0';
    }
}
