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
     * ## 三个来源，顺序不能乱（每一步都踩过坑）
     *
     *   ① Composer 的 `InstalledVersions`（装在应用里时）
     *   ② `framework/composer.json` 的相对路径（仓库内开发时）
     *   ③ 本包自己的 composer.json（只装了驱动包时的兜底）
     *
     * ⚠️ ① 必须在 ③ 之前。`aimanong/framework` 是 metapackage，
     * Composer **不为它建目录**，所以 ② 会落空；若此时直接落 ③，
     * 就会返回 laravel 的 1.0.0 —— 真实安装里实测到过这个错误，
     * 而且只有跑起来看界面才发现。
     */
    public static function version(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        /*
         * ① 装在应用里 → 用 Composer 运行时 API 取**产品包**版本。
         *
         *    ⚠️ 这里只认 `aimanong/framework`，**不能**把 `aimanong/laravel`
         *    也列进来。Composer 会把 **root 包**一并放进 InstalledVersions ——
         *    驱动包在仓库里跑测试时它自己就是 root，于是 laravel 会抢先命中，
         *    返回 1.0.0 而不是产品的 1.9.0。（实测踩到。）
         *    「只装了驱动包」的情况由 ③ 兜底。
         */
        if (class_exists(InstalledVersions::class)
            && InstalledVersions::isInstalled('aimanong/framework')) {
            $ver = InstalledVersions::getPrettyVersion('aimanong/framework');

            if (is_string($ver) && $ver !== '') {
                return $cached = $ver;
            }
        }

        // ② 仓库内开发（驱动包自己就是 root 包，InstalledVersions 里没有 framework）
        $fwComposer = __DIR__.'/../../framework/composer.json';

        if (($ver = self::versionFromComposer($fwComposer)) !== null) {
            return $cached = $ver;
        }

        // ③ 兜底：只装了驱动包
        if (($ver = self::versionFromComposer(__DIR__.'/../composer.json')) !== null) {
            return $cached = $ver;
        }

        return $cached = '1.0.0';
    }

    /**
     * 从 composer.json 读 version 字段；读不到返回 null。
     */
    protected static function versionFromComposer(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable) {
            return null;
        }

        if (is_array($decoded)
            && isset($decoded['version'])
            && is_string($decoded['version'])
            && $decoded['version'] !== '') {
            return $decoded['version'];
        }

        return null;
    }
}
