<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

/**
 * ThinkPHP 侧尚未实现的运行时能力的**显式空实现**。
 *
 * 为什么不用一个「什么都返回空」的通用对象：那样调用方看不出自己在调用
 * 什么，出问题时也不知道是「功能没做」还是「数据为空」。
 * 这里每个能力一个具名类，`get_class()` 一眼就能看出是空实现。
 */

/** 认证守卫：尚未实现，一律视为未登录。 */
final class NullGuard
{
    public function user(): ?object
    {
        return null;
    }

    public function check(): bool
    {
        return false;
    }

    public function guest(): bool
    {
        return true;
    }
}

/** 应用上下文：只提供固定的前缀。 */
final class NullContext
{
    public function __construct(private readonly string $prefix = '/admin') {}

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function guard(): NullGuard
    {
        return new NullGuard;
    }
}

/** 扩展管理器：尚未实现，没有任何扩展。 */
final class NullExtensions
{
    /**
     * @return array<int, string>
     */
    public function booted(): array
    {
        return [];
    }
}
