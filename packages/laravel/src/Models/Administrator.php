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
        'email',
        'phone',
        'password',
        'avatar',
        'enabled',
        'last_login_at',
        'last_login_ip',
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
            // 'hashed' 让「赋值明文 → 自动哈希」，
            // 因此后台表单可以直接收明文密码，无需控制器手动 Hash::make
            'password' => 'hashed',
            'preferences' => 'array',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * 记录一次成功登录。
     *
     * 给「用户管理」列表提供「最后登录时间/IP」——
     * 排查「这个账号还在用吗」「是不是异地登录」都靠它。
     */
    public function markLoggedIn(?string $ip): void
    {
        try {
            $this->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $ip,
            ])->save();
        } catch (\Throwable) {
            // 老项目可能还没跑迁移，缺这两列。
            // 记不上登录时间不该导致登录失败。
        }
    }

    /**
     * 是否是启用状态。
     *
     * 停用的账号必须拦在登录之前 —— 否则「停用」只是个装饰。
     */
    public function isEnabled(): bool
    {
        /** @var mixed $value */
        $value = $this->getAttribute('enabled');

        return $value === null || (bool) $value;
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
