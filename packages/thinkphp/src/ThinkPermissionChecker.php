<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Aimanong;
use Aimanong\Contracts\PermissionChecker;
use Aimanong\ThinkPHP\Models\Administrator;
use think\facade\Config;

/**
 * 权限判定（ThinkPHP 版）—— 数据库驱动，与 Laravel 侧同一套语义。
 *
 * ## 判定顺序（**顺序就是语义**）
 *
 *   ① 超管角色（`is_super`）→ 放行，绕过一切
 *   ② RBAC 未启用 → 放行
 *   ③ 未登录 → 拒绝
 *   ④ 查该用户所有角色的权限节点 → 命中即放行
 *   ⑤ 其余 → 拒绝
 *
 * ## ⚠️ 与第一版的差异：RBAC 未启用时**放行**，不是拒绝
 *
 * 第一版只做「配置数组列举即拥有，否则拒绝」，那是**默认拒绝**。
 * 而 Laravel 侧的语义是：`aimanong.auth.rbac` 默认 **false**，
 * 此时**根本不做权限判定**（RBAC 是 opt-in 的）。
 *
 * 两边不一致比两边都严格更危险 —— 同一个项目换框架后行为会变。
 * 现在对齐：`rbac = false` → 放行；`rbac = true` → 走数据库角色。
 */
final class ThinkPermissionChecker implements PermissionChecker
{
    public function check(string $slug): bool
    {
        $user = Aimanong::user();

        // ① 超级管理员绕过一切判定
        if ($user instanceof Administrator && $user->isSuper()) {
            return true;
        }

        // ② RBAC 未启用 → 不做判定（与 Laravel 侧一致；RBAC 是 opt-in）
        if (! $this->rbacEnabled()) {
            return true;
        }

        // ③ RBAC 开着但没登录 → 拒绝（不能让未登录者凭「没角色」蒙混过关）
        if (! $user instanceof Administrator) {
            return false;
        }

        // ④ 查该用户所有角色的权限节点
        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                if ((string) $permission->getAttr('slug') === $slug) {
                    return true;
                }
            }
        }

        // ⑤ 没命中
        return false;
    }

    public function rbacEnabled(): bool
    {
        try {
            return (bool) Config::get('aimanong.auth.rbac', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>|string|null  $action
     */
    public function slug(string $uri, array|string|null $action = null): string
    {
        if (is_array($action)) {
            $action = $action[0] ?? null;
        }

        $action = is_string($action) && $action !== '' ? $action : 'index';

        return trim($uri, '/').'.'.$action;
    }
}
