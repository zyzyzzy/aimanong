<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Models;

use think\Model;
use think\model\relation\BelongsToMany;

/**
 * 角色。
 *
 * 表结构与 Laravel 侧的 `admin_roles` 对齐。
 * 权限用**字符串 slug**（如 `orders.update`）而非自增 id ——
 * AI 与人都能读懂，也不需要查表才知道权限名。
 */
class Role extends Model
{
    /** @var string */
    protected $name = 'admin_roles';

    /** @var bool */
    protected $autoWriteTimestamp = true;

    /** @var string */
    protected $createTime = 'created_at';

    /** @var string */
    protected $updateTime = 'updated_at';

    /**
     * @var array<string, string>
     */
    protected $type = [
        'is_super' => 'boolean',
        'sort' => 'integer',
    ];

    public function isSuper(): bool
    {
        return (bool) $this->getAttr('is_super');
    }

    /**
     * 角色 → 权限（多对多）。
     */
    public function permissions(): BelongsToMany
    {
        /*
         * ⚠️ **参数顺序与 Laravel 相反**，这里踩过大坑。
         *
         * ThinkPHP：`belongsToMany($related, $middle, $foreignKey, $localKey)`
         *   $foreignKey = 中间表里指向**关联模型**的列
         *   $localKey   = 中间表里指向**当前模型**的列
         * Laravel：后两个参数正好反过来（先本模型、再关联模型）。
         *
         * 写成 Laravel 的顺序时，TP 生成的 SQL 会**把两个键对调**：
         *   ON pivot.role_id = admin_permissions.id  WHERE pivot.permission_id = ?
         * 于是「角色有哪些权限」永远查不出来 —— 而**不报任何错**，只是空数组。
         *
         * 更阴的是它可能**碰巧查对**：Role id 与 user id 相同、或某行的
         * role_id 恰好等于另一模型的 id 时，结果看起来正常。
         * 实测就是这个情况：`$user->roles` 返回了正确的角色，
         * 让人以为关联没问题 —— 直到去查 `$role->permissions` 才发现恒为空。
         */

        return $this->belongsToMany(Permission::class, 'admin_permission_role', 'permission_id', 'role_id');
    }

    /**
     * 角色 → 用户（多对多）。
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(Administrator::class, 'admin_role_user', 'user_id', 'role_id');
    }
}
