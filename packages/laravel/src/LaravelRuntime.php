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

    /**
     * 后台 URL 前缀。
     *
     * ⚠️ **必须归一化成带前导斜杠**（`/admin` 而不是 `admin`）。
     *
     * `Aimanong::url()` 拿它去拼 URL，而生成的 URL 会被放进 `href` / `src`：
     *
     *     href="admin/assets/css/design-system.css"     ← 没有前导斜杠
     *
     * 这是**相对路径**，浏览器按当前页面的 baseURI 解析：
     *
     *     页 /admin           → /admin/assets/...          ✅（base 是 /，碰巧对）
     *     页 /admin/articles  → /admin/admin/assets/...    ❌ 全 404
     *
     * 也就是说**控制台页正常、资源页面全坏** —— 无样式、Vue 加载不到、
     * 菜单点不动。而这个 bug 躲过了 223 个单测 + 全部 e2e，
     * 因为e2e 只测了控制台页（深度 1）。
     *
     * 最后是 5 个互不相识的 AI agent 在真实浏览器里各自撞到并报上来的。
     *
     * ThinkPHP 侧的 `prefixOf()` 一直是归一化的，所以只有 Laravel 中招 ——
     * 两端行为不一致本身也是问题（同一份声明应当产出同样的 URL）。
     */
    public function urlPrefix(): string
    {
        $prefix = trim($this->context()->prefix(), '/');

        return $prefix === '' ? '' : '/'.$prefix;
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
