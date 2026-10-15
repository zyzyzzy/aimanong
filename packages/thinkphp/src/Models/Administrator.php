<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Models;

use Aimanong\Contracts\Permissible;
use think\facade\Db;
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
class Administrator extends Model implements Permissible
{
    /** @var string */
    protected $name = 'admin_users';

    /** @var bool */
    protected $autoWriteTimestamp = true;

    /** @var string */
    protected $createTime = 'created_at';

    /** @var string */
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
        /*
         * ⚠️ 必须按 `user_type` 过滤：多应用下各后台有各自的用户表，
         * 不同表的 id 会碰撞（Administrator id=1 与 Agent id=1 是同一行）。
         *
         * ⚠️ 而 ThinkORM 的 `wherePivot` **同样只作用于读取** ——
         * 写入（attach/sync）不会带上该字段。所以挂角色必须走
         * `assignRole()`（显式写 user_type），不能用裸 attach。
         * （Laravel 侧同源的坑：wherePivot 写入不生效，要 withPivotValue。）
         */
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

        return $this->belongsToMany(Role::class, 'admin_role_user', 'role_id', 'user_id')
            ->wherePivot('user_type', static::class);
    }

    /**
     * 挂角色（显式写 user_type，保证与 `roles()` 的过滤对得上）。
     *
     * @param  array<int, int>|int  $roles
     */
    public function assignRole(array|int $roles): void
    {
        $ids = is_array($roles) ? $roles : [$roles];

        foreach ($ids as $roleId) {
            $exists = Db::name('admin_role_user')
                ->where('role_id', $roleId)
                ->where('user_id', $this->getKey())
                ->where('user_type', static::class)
                ->find();

            if ($exists === null) {
                Db::name('admin_role_user')->insert([
                    'role_id' => $roleId,
                    'user_id' => $this->getKey(),
                    'user_type' => static::class,
                ]);
            }
        }
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                if ((string) $permission->getAttr('slug') === $slug) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getAttr('enabled');
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
