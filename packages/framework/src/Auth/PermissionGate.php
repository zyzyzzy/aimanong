<?php

declare(strict_types=1);

namespace Aimanong\Auth;

use Aimanong\Aimanong;
use Aimanong\Models\Administrator;
use Aimanong\Models\Permission;
use Aimanong\Models\Role;
use Aimanong\Schema\Compiler;
use Illuminate\Support\Collection;

/**
 * 权限判定。
 *
 * ## 设计要点：权限节点由 Resource 自动生成
 *
 * 每个注册的 Resource 自动生成 6 个权限节点：
 *   `{uri}.index` / `{uri}.show` / `{uri}.create`
 *   / `{uri}.update` / `{uri}.destroy` / `{uri}.export`
 *
 * 例：`shop-products` → `shop-products.update`
 *
 * 这样用户**不需要手写权限节点**，注册 Resource 就有了 ——
 * 符合框架的声明式原则，也让 AI 可以自省「有哪些权限」。
 *
 * ## 可关闭
 *
 * `config('aimanong.auth.rbac')` 为 false 时全部放行
 * （保留不启用 RBAC 的灵活性）。
 */
class PermissionGate
{
    /**
     * Resource 自动生成的操作节点。
     *
     * @var array<int, string>
     */
    public const ACTIONS = ['index', 'show', 'create', 'update', 'destroy', 'export'];

    /**
     * 是否启用 RBAC。未启用时一律放行。
     */
    public static function enabled(): bool
    {
        /*
         * 兜住无容器场景（单元测试 / CLI 早期）：
         * config() 会抛异常，不能让权限判定本身崩掉。
         */
        try {
            return (bool) config('aimanong.auth.rbac', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 当前登录用户是否有某权限。
     */
    public static function check(string $slug): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $user = self::user();

        if (! $user instanceof Administrator) {
            return false;
        }

        // 未启用 enabled 的账号直接拒绝
        if (! $user->enabled) {
            return false;
        }

        return $user->hasPermission($slug);
    }

    /**
     * 当前登录用户。
     */
    public static function user(): ?Administrator
    {
        $user = Aimanong::guard()->user();

        return $user instanceof Administrator ? $user : null;
    }

    /**
     * 把 Resource 的操作映射成权限 slug。
     *
     * @param  array<int, string>|string|null  $action
     */
    public static function slug(string $uri, array|string|null $action = null): string
    {
        return $uri.'.'.(is_array($action) ? 'index' : ($action ?? 'index'));
    }

    /**
     * 同步权限节点到数据库。
     *
     * 会把当前所有已注册 Resource 的权限节点写入 admin_permissions，
     * 旧的孤儿节点**保留**（避免误删用户已配置的权限）。
     *
     * @return array{created: int, existing: int}
     */
    public static function sync(): array
    {
        $created = 0;
        $existing = 0;

        /*
         * registry()->all() 返回的是**类名**数组（不是 ResourceNode），
         * 需要编译才能拿到 uri/label。
         * 编译失败（如声明有误）的 Resource 跳过，不让整个同步崩掉。
         */
        $compiler = new Compiler;

        foreach (Aimanong::registry()->all() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            try {
                $node = $compiler->compile($class);
            } catch (\Throwable) {
                continue;
            }

            $uri = $node->uri;
            $label = $node->label;

            foreach (self::ACTIONS as $action) {
                $slug = self::slug($uri, $action);

                if (Permission::query()->where('slug', $slug)->exists()) {
                    $existing++;

                    continue;
                }

                Permission::query()->create([
                    'slug' => $slug,
                    'name' => $label.' · '.self::actionLabel($action),
                    'group' => $label,
                ]);

                $created++;
            }
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * 全部权限节点（供自省与角色分配界面）。
     *
     * @return array<int, array{slug: string, name: string, group: string}>
     */
    public static function all(): array
    {
        /** @var Collection<int, Permission> $rows */
        $rows = Permission::query()->orderBy('group')->orderBy('slug')->get(['slug', 'name', 'group']);

        $out = [];

        foreach ($rows as $p) {
            $out[] = [
                'slug' => $p->slug,
                'name' => $p->name,
                'group' => $p->group ?? '',
            ];
        }

        return $out;
    }

    /**
     * 操作的中文名。
     */
    public static function actionLabel(string $action): string
    {
        return [
            'index' => '查看列表',
            'show' => '查看详情',
            'create' => '新增',
            'update' => '编辑',
            'destroy' => '删除',
            'export' => '导出',
        ][$action] ?? $action;
    }

    /**
     * 确保存在超级管理员角色，并把指定用户加入。
     *
     * 用于安装/初始化。幂等。
     */
    public static function ensureSuperRole(?Administrator $user = null): Role
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => '超级管理员',
                'description' => '拥有全部权限，绕过所有判定',
                'is_super' => true,
            ]
        );

        if ($user !== null && ! $role->users()->whereKey($user->getKey())->exists()) {
            $role->users()->attach($user->getKey());
        }

        return $role;
    }
}
