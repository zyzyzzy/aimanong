<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Application\ApplicationContext;
use Aimanong\Application\ApplicationManager;
use Aimanong\Auth\AdminGuard;
use Aimanong\Contracts\FrameworkRuntime;
use Aimanong\Extend\ExtensionManager;
use Aimanong\Support\Asset;

/**
 * Laravel 运行时 —— `Contracts\FrameworkRuntime` 的 Laravel 实现。
 *
 * 这里集中了公开入口 `Aimanong\Aimanong` 原先直接调 Laravel 设施的那些部分
 * （`app('auth')` / `app('aimanong.context')` / `config()` …）。
 * 搬到这个类之后，入口本身住进 core，两个框架共用同一个类名。
 */
final class LaravelRuntime implements FrameworkRuntime
{
    /**
     * 应用上下文 —— 推断当前后台的唯一入口。
     *
     * 所有需要「当前应用的前缀 / guard」的地方都应通过它获取，
     * 而不是直接读 config（多应用下 config 会被 switch() 污染）。
     */
    public function context(): ApplicationContext
    {
        /** @var ApplicationContext $c */
        $c = app('aimanong.context');

        return $c;
    }

    public function urlPrefix(): string
    {
        return $this->context()->prefix();
    }

    /**
     * 多应用管理器。
     */
    public function application(): ApplicationManager
    {
        /** @var ApplicationManager $m */
        $m = app('aimanong.application');

        return $m;
    }

    /**
     * 扩展管理器。
     */
    public function extensions(): ExtensionManager
    {
        /** @var ExtensionManager $m */
        $m = app('aimanong.extensions');

        return $m;
    }

    /**
     * 获取当前后台的 guard。
     *
     * 多应用下 config('aimanong.auth.guard') 会被最后一次 switch() 覆盖，
     * 直接读它会导致 admin 请求拿到 merchant 的 guard
     * （session key 不一致 → 登录态丢失 → 无限重定向）。
     *
     * 因此与 url() 同理：从当前请求路径推断所属应用，再取该应用的 guard。
     */
    public function guard(): AdminGuard
    {
        /** @var AdminGuard $guard */
        $guard = app('auth')->guard($this->context()->guard());

        return $guard;
    }

    /**
     * 当前登录管理员。
     */
    public function user(): ?object
    {
        return $this->guard()->user();
    }

    /**
     * 资源登记器。
     */
    public function asset(): Asset
    {
        /** @var Asset $asset */
        $asset = app('aimanong.asset');

        return $asset;
    }

    public function registry(): Registry
    {
        /** @var Registry $registry */
        $registry = app(Registry::class);

        return $registry;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config($key, $default);
    }
}
