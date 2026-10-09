<?php

declare(strict_types=1);

namespace Aimanong\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Laravel 11+ 契约要求 getAuthPasswordName()。
     * Illuminate\Auth\Authenticatable trait 已提供实现与 $authPasswordName 属性，
     * 此处不可重复声明该属性（会触发 PHP fatal: 属性定义不兼容）。
     */
}
