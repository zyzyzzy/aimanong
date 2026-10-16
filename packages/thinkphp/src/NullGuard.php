<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

/**
 * 认证守卫的**显式空实现**（尚未实现的运行时能力）。
 *
 * 为什么不用一个「什么都返回空」的通用对象：那样调用方看不出自己在调用
 * 什么，出问题时也不知道是「功能没做」还是「数据为空」。
 * 这里每个能力一个具名类，`get_class()` 一眼就能看出是空实现。
 */
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
