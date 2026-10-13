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
        return $this->belongsToMany(Permission::class, 'admin_permission_role', 'role_id', 'permission_id');
    }

    /**
     * 角色 → 用户（多对多）。
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(Administrator::class, 'admin_role_user', 'role_id', 'user_id');
    }
}
