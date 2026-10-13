<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Models;

use think\Model;
use think\model\relation\BelongsToMany;

/**
 * 权限节点。
 *
 * `group` 只是**展示分类**字段，不做「权限组」表 ——
 * 多一张表换不来什么，却要多维护一层关联。
 */
class Permission extends Model
{
    /** @var string */
    protected $name = 'admin_permissions';

    /** @var bool */
    protected $autoWriteTimestamp = true;

    /** @var string */
    protected $createTime = 'created_at';

    /** @var string */
    protected $updateTime = 'updated_at';

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_permission_role', 'permission_id', 'role_id');
    }
}
