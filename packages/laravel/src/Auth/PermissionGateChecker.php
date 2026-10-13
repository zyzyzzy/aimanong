<?php

declare(strict_types=1);

namespace Aimanong\Auth;

use Aimanong\Contracts\PermissionChecker;

/**
 * `PermissionGate` 的实例适配器。
 *
 * `PermissionGate` 是纯静态 API（`PermissionGate::check()`），
 * 而 core 需要的是一个**实例契约** —— 否则 core 就得直接写
 * `Aimanong\Auth\PermissionGate::check()`，把 core 绑到 Laravel 包上。
 *
 * 这里只做转发，不含逻辑：契约声明两个方法，就实现两个方法。
 */
final class PermissionGateChecker implements PermissionChecker
{
    public function check(string $slug): bool
    {
        return PermissionGate::check($slug);
    }

    /**
     * @param  array<int, string>|string|null  $action
     */
    public function slug(string $uri, array|string|null $action = null): string
    {
        return PermissionGate::slug($uri, $action);
    }
}
