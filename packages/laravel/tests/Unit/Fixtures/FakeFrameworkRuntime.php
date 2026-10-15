<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Contracts\FrameworkRuntime;
use Aimanong\Registry;
use Aimanong\Support\Asset;

/**
 * 测试用的框架运行时。
 *
 * ## 为什么需要
 *
 * 单测不起 Laravel 应用，所以 `LaravelRuntime` 用不了（它依赖容器）。
 * 但 `Aimanong::registry()` 又确实要求注册 `FrameworkRuntime` ——
 * 这是刻意的设计（公开入口必须绑定运行时，否则抛未注册）。
 *
 * 所以给测试一个最小实现：只把 `registry()` 接出去，其余返回安全默认值。
 */
class FakeFrameworkRuntime implements FrameworkRuntime
{
    public function __construct(private readonly Registry $registry = new Registry) {}

    /**
     * 故意返回**未归一化**的形式（配置里的原样值 ）。
     *
     * 真实的 LaravelRuntime 曾经就是这样 —— 于是 Aimanong::url() 拼出
     * （相对路径），在深度≥2 的页面里全 404。
     * 让假件保持这个形态，才能测出归一化是否真的生效。
     */
    public function urlPrefix(): string
    {
        return 'admin';
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    public function asset(): Asset
    {
        return new Asset;
    }

    public function user(): ?object
    {
        return null;
    }

    public function guard(): object
    {
        return new \stdClass;
    }

    public function context(): object
    {
        return new \stdClass;
    }

    public function application(): object
    {
        return new \stdClass;
    }

    public function extensions(): object
    {
        return new \stdClass;
    }
}
