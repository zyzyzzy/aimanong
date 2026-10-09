<?php

declare(strict_types=1);

namespace Aimanong\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 管理员模型。
 *
 * 使用 Illuminate\Auth\Authenticatable trait，它已自带
 * Laravel 11+ 契约新增的 getAuthPasswordName()，
 * 无需手写 —— 但自定义密码字段名时需覆盖 $authPasswordName。
 */
/**
 * @use HasFactory<Factory<static>>
 */
class Administrator extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasFactory;

    protected $table = 'admin_users';

    protected $fillable = [
        'username',
        'name',
        'password',
        'avatar',
        'enabled',
    ];

    /**
     * 用户的角色。
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_role_user', 'user_id', 'role_id');
    }

    /**
     * 是否超级管理员（任一角色 is_super）。
     */
    public function isSuper(): bool
    {
        return $this->roles()->where('is_super', true)->exists();
    }

    /**
     * 是否有某个权限。
     *
     * 判定顺序：超级管理员 → 直接放行；否则查角色关联的权限 slug。
     */
    public function hasPermission(string $slug): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', function ($q) use ($slug): void {
                $q->where('slug', $slug);
            })
            ->exists();
    }

    /**
     * 界面偏好（主题/密度/圆角）。
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Laravel 11+ 契约要求 getAuthPasswordName()。
     * Illuminate\Auth\Authenticatable trait 已提供实现与 $authPasswordName 属性，
     * 此处不可重复声明该属性（会触发 PHP fatal: 属性定义不兼容）。
     */
}
