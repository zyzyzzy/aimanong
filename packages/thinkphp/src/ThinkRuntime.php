<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Contracts\FrameworkRuntime;
use Aimanong\Registry;
use Aimanong\Support\Asset;
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
 * | 多应用 | ApplicationManager | **未实现**（返回 NullApplication） |
 * | 扩展 | ExtensionManager | **未实现**（返回 NullExtensions） |
 *
 * 「未实现」的部分返回**明确命名的空对象**，而不是随便糊一个对象 ——
 * 这样调用方至少能看到自己在调用什么，而不是拿到一个会在别处炸掉的东西。
 * 完整的认证与多应用留到后续增量。
 */
final class ThinkRuntime implements FrameworkRuntime
{
    protected ?Registry $registry = null;

    public function urlPrefix(): string
    {
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
        try {
            $id = session('aimanong_admin_id');

            if ($id === null || $id === '') {
                return null;
            }

            $model = $this->config('aimanong.auth.model');

            if (! is_string($model) || ! class_exists($model)) {
                return null;
            }

            return $model::find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    public function guard(): object
    {
        return new NullGuard;
    }

    public function context(): object
    {
        return new NullContext($this->urlPrefix());
    }

    public function application(): object
    {
        return new NullApplication($this->urlPrefix());
    }

    public function extensions(): object
    {
        return new NullExtensions;
    }
}
