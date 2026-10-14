<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Contracts\FrameworkRuntime;
use Aimanong\Registry;
use Aimanong\Support\Asset;
use Aimanong\ThinkPHP\Auth\ThinkGuard;
use think\facade\Config;

/**
 * ThinkPHP 运行时 —— `Contracts\FrameworkRuntime` 的 ThinkPHP 实现。
 *
 * 公开入口 `Aimanong\Aimanong` 住在 core，两个框架共用同一个类名
 * （用户代码与 10 个模板都写 `Aimanong::url()` 这种）。
 * 这里承接它在 ThinkPHP 侧需要的一切。
 *
 * ## 与 Laravel 实现的差异（如实说明，不假装等价）
 *
 * | 能力 | Laravel | ThinkPHP（当前） |
 * |---|---|---|
 * | url 前缀 | 多应用按请求路径推断 | 固定配置项 `aimanong.prefix` |
 * | guard | AdminGuard（完整认证） | **未实现** —— 见下 |
 * | user | guard 的当前用户 | 从会话读 `aimanong_admin_id` |
 * | 多应用 | ApplicationManager | `Application\ThinkApplication`（已实现） |
 * | 扩展 | ExtensionManager | **未实现**（返回 NullExtensions） |
 *
 * 「未实现」的部分返回**明确命名的空对象**，而不是随便糊一个对象 ——
 * 这样调用方至少能看到自己在调用什么，而不是拿到一个会在别处炸掉的东西。
 * 完整的认证与多应用留到后续增量。
 */
final class ThinkRuntime implements FrameworkRuntime
{
    protected ?Registry $registry = null;

    protected ?ThinkGuard $guardInstance = null;

    protected ?Application\ThinkApplication $applicationInstance = null;

    /**
     * 当前后台的路由前缀。
     *
     * 多应用时取**当前应用**的前缀；否则退回 `aimanong.prefix`
     * （单应用项目的既有配置，保持兼容）。
     */
    public function urlPrefix(): string
    {
        return $this->prefixOf($this->application()->current());
    }

    /**
     * 指定应用的路由前缀（路由注册要逐个应用各来一遍）。
     */
    public function prefixOf(string $app): string
    {
        $apps = $this->application();

        if ($apps->enabled() && $apps->prefix($app) !== '') {
            return '/'.trim($apps->prefix($app), '/');
        }

        $prefix = $this->config('aimanong.prefix', '/admin');

        return is_string($prefix) && $prefix !== '' ? '/'.trim($prefix, '/') : '/admin';
    }

    public function config(string $key, mixed $default = null): mixed
    {
        try {
            return Config::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function registry(): Registry
    {
        // 注册表是纯 core 对象，TP 侧自己持有一个单例即可
        return $this->registry ??= new Registry;
    }

    public function asset(): Asset
    {
        return new Asset;
    }

    /**
     * 当前登录管理员。
     *
     * 只做「从会话里读 id」这一种最简形态。完整的认证流程
     * （登录 / 限流 / 记住我）留到后续增量 —— 现在**不谎报**：
     * 没有登录态就返回 null。
     */
    public function user(): ?object
    {
        return $this->guard()->user();
    }

    /**
     * 会话守卫。
     *
     * 单例：登录态在一次请求里应当一致 —— 每次 new 一个会在
     * 「守卫内部做了 logout 清理」之后拿到不一致的结果。
     */
    public function guard(): ThinkGuard
    {
        /*
         * ⚠️ session key 要带**应用维度**。
         *
         * 否则三个后台共用同一个 session key：在 /admin 登录后，
         * /agent 会读到同一个 id 去查 Agent 表 —— 要么查不到（表现为
         * 「登录了但立刻掉线」），要么**查到同 id 的另一个人**（越权）。
         *
         * 这与 Laravel 侧的 ApplicationContext 注释里那个坑同源
         * （session key 不一致 → 无限重定向）。
         */
        $base = $this->config('aimanong.auth.session_key');
        $base = is_string($base) && $base !== '' ? $base : 'aimanong_admin_id';

        $apps = $this->application();
        $key = $apps->enabled() ? $base.'_'.$apps->current() : $base;

        return $this->guardInstance ??= new ThinkGuard($key);
    }

    public function context(): object
    {
        // 前缀按当前应用解析；guard 交给 guard() 自己（带应用维度）
        return new NullContext($this->urlPrefix());
    }

    public function application(): Application\ThinkApplication
    {
        // 单例：一次请求里「当前是哪个后台」必须一致
        return $this->applicationInstance ??= new Application\ThinkApplication;
    }

    public function extensions(): object
    {
        return new NullExtensions;
    }
}
