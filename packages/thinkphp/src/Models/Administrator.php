<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Models;

use think\Model;
use think\model\relation\BelongsToMany;

/**
 * 后台管理员。
 *
 * 表结构与 Laravel 侧的 `admin_users` 对齐（同一套声明应当能共用同一张表）：
 *
 *   id / username / name / password / avatar / enabled
 *   remember_token / preferences / created_at / updated_at
 *
 * ## 密码哈希为什么用 password_hash()
 *
 * Laravel 用 `Hash::make()`（bcrypt），TP 侧没有对应的门面。
 * 但两边最终都是 **PHP 原生的 password_hash / password_verify** ——
 * 所以同一个哈希在两侧都能通过校验，用户从 Laravel 迁到 TP 不用重设密码。
 * 这一点是刻意保证的，不是巧合。
 */
class Administrator extends Model
{
    protected $name = 'admin_users';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'created_at';

    protected $updateTime = 'updated_at';

    /**
     * 列表输出时不带密码。
     *
     * @var array<int, string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @var array<string, string>
     */
    protected $type = [
        'enabled' => 'integer',
        'preferences' => 'json',
    ];

    /**
     * 用户 → 角色（多对多）。
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_role_user', 'user_id', 'role_id');
    }

    public function isSuper(): bool
    {
        foreach ($this->roles as $role) {
            if ($role->isSuper()) {
                return true;
            }
        }

        return false;
    }

    public function verifyPassword(string $plain): bool
    {
        $hash = (string) $this->getAttr('password');

        return $hash !== '' && password_verify($plain, $hash);
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }
}
