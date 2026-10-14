<?php

declare(strict_types=1);

namespace Aimanong\Concerns;

use Aimanong\Models\Role;

/**
 * 让任意用户模型接入 Aimanong 的权限体系。
 *
 * ## 为什么需要它
 *
 * 多应用下每个后台有自己的用户模型（管理员 / 用户 / 代理商），
 * 都要能被权限判定。若每个模型各写一遍「角色关联 + 权限查询」，
 * 三份逻辑必然漂移（前面跨框架共出现 14 处语义差异，就是这么来的）。
 *
 * 用法：
 *
 * ```php
 * class Agent extends Model implements Authenticatable, Permissible
 * {
 *     use HasAimanongPermissions;
 * }
 * ```
 *
 * 要求：模型所在库有 `admin_role_user`（user_id / role_id）关联表。
 */
trait HasAimanongPermissions
{
    /**
     * 该用户的角色（多对多）。
     *
     * ⚠️ **必须按用户类型过滤**。
     *
     * `admin_role_user` 只有 `user_id`，而多应用下每个后台有**自己的用户表**
     * （admin_users / users / agents）。不同表的 id 会**碰撞** ——
     * 实测踩到：Administrator id=1 与 Agent id=1 是同一行记录，
     * 于是给管理员挂的超管角色，代理商登录后也拿到了（越权！）。
     *
     * 所以加上 `user_type` 维度（存模型类名），只取属于本类型的关联。
     */
    public function aimanongRoles()
    {
        // `withPivotValue` 读写都生效（`wherePivot` 只管读，写入会漏字段）
        return $this->belongsToMany(Role::class, 'admin_role_user', 'user_id', 'role_id')
            ->withPivotValue('user_type', static::class);
    }

    /**
     * 挂角色时带上 user_type（与 aimanongRoles 的过滤对应）。
     *
     * @param  array<int, int>|int  $roles
     */
    public function assignAimanongRole($roles): static
    {
        $ids = is_array($roles) ? $roles : [$roles];
        $existing = \DB::table('admin_role_user')
            ->where('user_id', $this->getKey())
            ->where('user_type', static::class)
            ->pluck('role_id')
            ->all();

        $attach = array_diff($ids, $existing);

        foreach ($attach as $roleId) {
            \DB::table('admin_role_user')->insert([
                'user_id' => $this->getKey(),
                'role_id' => $roleId,
                'user_type' => static::class,
            ]);
        }

        return $this;
    }

    public function isSuper(): bool
    {
        return $this->aimanongRoles()
            ->where('is_super', true)
            ->exists();
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        return $this->aimanongRoles()
            ->whereHas('permissions', function ($q) use ($slug): void {
                $q->where('slug', $slug);
            })
            ->exists();
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getAttribute('enabled');
    }
}
