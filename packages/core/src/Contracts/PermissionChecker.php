<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 权限判定契约。
 *
 * `MenuRegistry` 用它把「当前用户看不到的菜单」过滤掉。
 *
 * ## 为什么只有这两个方法
 *
 * 契约只收 core 真正调用到的表面。`PermissionGate` 还有
 * `actionsFor()` / `sync()` / `ensureSuperRole()` 等能力，
 * 那些属于驱动自身的功能，不经过 core，所以不进契约的。
 * 契约越小，驱动越好实现 —— ThinkPHP 侧只需实现这两个。
 */
interface PermissionChecker
{
    /**
     * 当前用户是否拥有该权限节点。
     */
    public function check(string $slug): bool;

    /**
     * 由资源 uri 与动作拼出权限节点名（如 `order.index`）。
     *
     * @param  array<int, string>|string|null  $action
     */
    public function slug(string $uri, array|string|null $action = null): string;
}
