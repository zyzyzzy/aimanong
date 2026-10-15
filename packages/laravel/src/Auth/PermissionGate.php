<?php

declare(strict_types=1);

namespace Aimanong\Auth;

use Aimanong\Aimanong;
use Aimanong\Contracts\Permissible;
use Aimanong\Models\Administrator;
use Aimanong\Models\Permission;
use Aimanong\Models\Role;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Schema\Compiler;
use Aimanong\Support\Drivers;
use Aimanong\Support\PermissionNodes;
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
    /** @see PermissionNodes::ACTIONS */
    public const ACTIONS = PermissionNodes::ACTIONS;

    /** @see PermissionNodes::READONLY_ACTIONS */
    public const READONLY_ACTIONS = PermissionNodes::READONLY_ACTIONS;

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

        if (! $user instanceof Permissible) {
            return false;
        }

        // 未启用的账号直接拒绝
        if (! $user->isEnabled()) {
            return false;
        }

        return $user->hasPermission($slug);
    }

    /**
     * 当前登录用户。
     */
    /**
     * 当前登录用户。
     *
     * ⚠️ 返回类型是 `object`，**不是** `Administrator`。
     *
     * 多应用下每个后台有自己的用户模型（管理员 / 用户 / 代理商），
     * 它们不是同一个类。若这里硬判 `Administrator`，
     * 其它后台的用户会被当成「未登录」→ 权限全部拒绝（登录了也 403）。
     *
     * 判定改为基于**能力**：实现 `Permissible` 契约的模型都认。
     */
    public static function user(): ?object
    {
        /** @var AdminGuard $guard */
        $guard = Aimanong::guard();
        $user = $guard->user();

        return $user instanceof Permissible ? $user : null;
    }

    /**
     * 把 Resource 的操作映射成权限 slug。
     *
     * @param  array<int, string>|string|null  $action
     */
    public static function slug(string $uri, array|string|null $action = null): string
    {
        return self::appSlug(null, $uri, $action);
    }

    /**
     * 带应用维度的权限节点。
     *
     * ## 为什么要加应用前缀
     *
     * 多应用下 `{uri}.{action}` 是**跨后台共享的同一个节点**：
     * 给代理商开了 `orders.index`，等于也开了总后台的 `orders.index`。
     * 权限必须跟菜单一样按后台隔离。
     *
     * 加了前缀之后（`admin.orders.index` / `agent.orders.index`），
     * **菜单隔离也自动成立** —— 因为菜单是按权限过滤的。
     * 一处改动解决两件事。
     *
     * ## 兼容性：单应用零影响
     *
     * **只在多应用开启时加前缀**。`applications` 为空（单后台）时
     * 仍是 `orders.index`，与改动前逐字相同 —— 已有授权不受影响。
     *
     * @param  string|null  $app  应用名；null = 当前应用
     */
    public static function appSlug(?string $app, string $uri, array|string|null $action = null): string
    {
        $base = $uri.'.'.(is_array($action) ? 'index' : ($action ?? 'index'));

        $apps = Drivers::application();

        // 显式指定应用时**强制加前缀**（即使当前是多应用未开启 / CLI 场景）
        if ($app !== null) {
            return $app.'.'.$base;
        }

        if (! $apps->enabled()) {
            return $base;
        }

        return $apps->current().'.'.$base;
    }

    /**
     * 某个 Resource 应该有哪些权限节点。
     *
     * 只读资源只生成 index/show/export —— 否则权限列表里会多出
     * 「操作日志 · 新增」这种点了必然 403 的假权限，
     * 管理员会以为是自己配错了。
     *
     * 抽成公开静态方法是为了可测：单据断言，不必真的写库。
     *
     * @return array<int, string>
     */
    public static function actionsFor(ResourceNode $node): array
    {
        // 实现在 core —— 两端共用同一套动作定义（TP 侧也要生成同样的节点）
        return PermissionNodes::actionsFor($node);
    }

    /**
     * 同步权限节点到数据库。
     *
     * 会把当前所有已注册 Resource 的权限节点写入 admin_permissions，
     * 旧的孤儿节点**保留**（避免误删用户已配置的权限）。
     *
     * @return array{created: int, existing: int}
     */
    /**
     * @param  string|null  $forApp  只为某应用建节点；null = 按当前上下文
     *                               （多应用时建全部应用，单应用时建不带前缀的）
     *
     * 为什么需要它：CLI（如迁移命令）没有请求上下文，
     * `Drivers::application()->current()` 拿不到当前后台 ——
     * 必须能显式指定「给哪个后台建节点」。
     */
    public static function sync(?string $forApp = null): array
    {
        $created = 0;
        $existing = 0;
        $expected = [];

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

            /*
             * 只读 Resource 不生成写权限节点。
             *
             * 否则权限列表里会出现「操作日志 · 新增」这种
             * 点了也一定 403 的假权限 —— 管理员会以为配错了。
             */
            /*
             * 多应用时，权限节点要按「应用 × 属于它的 Resource」展开。
             *
             * 否则 `/agent` 后台根本没有自己的节点可分配 ——
             * 而 `slug()` 已经在查 `agent.orders.index`，于是永远 403。
             * （只按当前应用建也不行：同步通常在总后台跑，那样代理商后台
             *   就一个节点都没有。）
             */
            $apps = Drivers::application();

            if ($forApp !== null) {
                $appList = [$forApp];
            } else {
                $appList = $apps->enabled() ? $apps->names() : [null];

                $expected = [];
            }

            foreach ($appList as $app) {
                if ($app !== null && ! Aimanong::registry()->belongsTo($uri, $app)) {
                    continue;
                }

                foreach (self::actionsFor($node) as $action) {
                    $slug = self::appSlug($app, $uri, $action);

                    $expected[$slug] = true;

                    if (Permission::query()->where('slug', $slug)->exists()) {
                        $existing++;

                        continue;
                    }

                    Permission::query()->create([
                        'slug' => $slug,
                        'name' => $label.' · '.self::actionLabel($action)
                            .($app !== null ? '（'.$apps->title($app).'）' : ''),
                        'group' => $app !== null ? $apps->title($app).' / '.$label : $label,
                    ]);

                    $created++;
                }
            }
        }

        $orphaned = Permission::query()
            ->whereNotIn('slug', array_keys($expected))
            ->count();

        return [
            'created' => $created,
            'existing' => $existing,
            // 孤儿子节点：库里存在，但当前没有「应用 × Resource」对得上。
            // 常见来源：改了 Resource::applications()、或删掉了 Resource。
            // 故意**不自动删** —— 那是用户已配置给角色的授权，误删比留着更糟。
            'orphaned' => $orphaned,
        ];
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
        return PermissionNodes::label($action);
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
