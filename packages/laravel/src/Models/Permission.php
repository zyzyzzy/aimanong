<?php

declare(strict_types=1);

namespace Aimanong\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 权限节点。
 *
 * slug 形如 `shop-products.update`（Resource uri + 操作），
 * 由 Resource 注册时**自动生成**，无需手写。
 */
class Permission extends Model
{
    protected $table = 'admin_permissions';

    protected $fillable = [
        'slug',
        'name',
        'group',
        'description',
    ];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_permission_role');
    }
}
