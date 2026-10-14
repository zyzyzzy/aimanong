<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 可被权限判定的用户模型契约。
 *
 * ## 为什么需要它
 *
 * 单应用时后台用户只有一种（`Administrator`），
 * `PermissionGate` 直接 `instanceof Administrator` 就够了。
 *
 * 多应用下**每个后台有自己的用户模型**（管理员 / 用户 / 代理商），
 * 它们不是同一个类 —— 硬判 Administrator 会把其它后台的用户当成「未登录」，
 * 于是**权限全部拒绝**（登录了也 403）。这个 bug 只有在真实配出多个后台时才会出现。
 *
 * 所以判定要基于**能力**而不是具体类。
 */
interface Permissible
{
    /**
     * 是否超级管理员（绕过一切权限判定）。
     */
    public function isSuper(): bool;

    /**
     * 是否有某权限节点。
     */
    public function hasPermission(string $slug): bool;

    /**
     * 账号是否启用。禁用账号即使密码正确也不放行。
     */
    public function isEnabled(): bool;
}
