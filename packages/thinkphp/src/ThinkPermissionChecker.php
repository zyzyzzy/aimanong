<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Contracts\PermissionChecker;
use think\facade\Config;

/**
 * ThinkPHP 权限判定 —— `Contracts\PermissionChecker` 实现。
 *
 * ## 当前实现的范围（**故意说清楚**）
 *
 * 只做「配置里列出的权限节点即视为拥有」这一种最简形态：
 *
 *     // config/aimanong.php
 *     'permissions' => ['order.index', 'order.create'],
 *
 * 它足以让菜单过滤**正确工作**（不谎报），但**不是**一个 RBAC 系统 ——
 * 没有角色、没有用户绑定、没有数据库。
 *
 * ## 为什么不先做个「假的全放行」
 *
 * `check()` 全返回 true 会让菜单永远全部可见 —— 看起来正常，
 * 实际是没有权限控制，属于宪法第三条禁止的幽灵能力。
 * 「配置驱动、默认拒绝」虽然是雏形，但它**不撒谎**：
 * 没配节点的菜单就是不显示。
 *
 * 完整的 RBAC（角色表 / 用户绑定 / 权限同步）留到后续增量。
 */
class ThinkPermissionChecker implements PermissionChecker
{
    /**
     * @return array<int, string>
     */
    public function granted(): array
    {
        try {
            /** @var mixed $value */
            $value = Config::get('aimanong.permissions', []);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }

    public function check(string $slug): bool
    {
        return in_array($slug, $this->granted(), true);
    }

    /**
     * @param  array<int, string>|string|null  $action
     */
    public function slug(string $uri, array|string|null $action = null): string
    {
        if (is_array($action)) {
            // 多动作时取第一个 —— 调用方（菜单过滤）本来只关心 index
            $action = $action[0] ?? null;
        }

        $action = is_string($action) && $action !== '' ? $action : 'index';

        return trim($uri, '/').'.'.$action;
    }
}
