<?php

declare(strict_types=1);

namespace Aimanong\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 角色。
 *
 * 与权限、用户均为多对多。
 * `is_super` 为超级管理员，绕过所有权限判定。
 */
class Role extends Model
{
    protected $table = 'admin_roles';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_super',
        'sort',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_super' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'admin_permission_role');
    }

    /**
     * @return BelongsToMany<Administrator, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(Administrator::class, 'admin_role_user', 'role_id', 'user_id');
    }
}
