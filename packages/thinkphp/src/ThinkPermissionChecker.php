<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Aimanong;
use Aimanong\Contracts\Permissible;
use Aimanong\Contracts\PermissionChecker;
use Aimanong\Schema\Compiler;
use Aimanong\Support\PermissionNodes;
use Aimanong\ThinkPHP\Application\ThinkApplication;
use Aimanong\ThinkPHP\Models\Administrator;
use Aimanong\ThinkPHP\Models\Permission;
use think\facade\Config;

/**
 * 权限判定（ThinkPHP 版）—— 数据库驱动，与 Laravel 侧同一套语义。
 *
 * ## 判定顺序（**顺序就是语义**）
 *
 *   ① 超管角色（`is_super`）→ 放行，绕过一切
 *   ② RBAC 未启用 → 放行
 *   ③ 未登录 → 拒绝
 *   ④ 查该用户所有角色的权限节点 → 命中即放行
 *   ⑤ 其余 → 拒绝
 *
 * ## ⚠️ 与第一版的差异：RBAC 未启用时**放行**，不是拒绝
 *
 * 第一版只做「配置数组列举即拥有，否则拒绝」，那是**默认拒绝**。
 * 而 Laravel 侧的语义是：`aimanong.auth.rbac` 默认 **false**，
 * 此时**根本不做权限判定**（RBAC 是 opt-in 的）。
 *
 * 两边不一致比两边都严格更危险 —— 同一个项目换框架后行为会变。
 * 现在对齐：`rbac = false` → 放行；`rbac = true` → 走数据库角色。
 */
final class ThinkPermissionChecker implements PermissionChecker
{
    public function check(string $slug): bool
    {
        $user = Aimanong::user();

        /*
         * ⚠️ 按**能力**判定，不硬判具体类。
         *
         * 多应用下每个后台有自己的用户模型（管理员 / 用户 / 代理商），
         * 硬判 `Administrator` 会把其它后台的用户当成「未登录」→ 全拒
         * （Laravel 侧就是这么踩的：登录了但菜单全空）。
         */
        $permissible = $user instanceof Permissible;

        // ① 超级管理员绕过一切判定
        if ($permissible && $user->isSuper()) {
            return true;
        }

        // ② RBAC 未启用 → 不做判定（与 Laravel 侧一致；RBAC 是 opt-in）
        if (! $this->rbacEnabled()) {
            return true;
        }

        // ③ RBAC 开着但没登录 → 拒绝（不能让未登录者凭「没角色」蒙混过关）
        if (! $permissible) {
            return false;
        }

        // ④ 账号被停用 → 拒绝
        if (! $user->isEnabled()) {
            return false;
        }

        // ⑤ 查该用户所有角色的权限节点
        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                if ((string) $permission->getAttr('slug') === $slug) {
                    return true;
                }
            }
        }

        // ⑥ 没命中
        return false;
    }

    public function rbacEnabled(): bool
    {
        try {
            return (bool) Config::get('aimanong.auth.rbac', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>|string|null  $action
     */
    public function slug(string $uri, array|string|null $action = null): string
    {
        return $this->appSlug(null, $uri, $action);
    }

    /**
     * 带应用维度的权限节点（与 Laravel 侧同一套语义）。
     *
     * ## 为什么加应用前缀
     *
     * 多应用下 `{uri}.{action}` 是**跨后台共享的同一个节点** ——
     * 给代理商开了 `crm-customers.index`，等于也开了总后台的。
     *
     * 加了前缀之后**菜单隔离也自动成立**（菜单按权限过滤），
     * 一处改动解决两件事。
     *
     * ## 兼容性：单应用零影响
     *
     * **只在多应用开启时加前缀**。未配 `applications` 时仍是
     * `crm-customers.index`，与改动前逐字相同。
     *
     * ## 显式指定应用时**强制**加前缀
     *
     * CLI（权限同步 / 迁移）没有请求上下文，拿不到「当前是哪个后台」，
     * 只能靠调用方指定。若这时还受 enabled() 影响，命令就永远建不出
     * 目标节点（Laravel 侧踩过）。
     *
     * @param  string|null  $app  应用名；null = 当前应用
     * @param  array<int, string>|string|null  $action
     */
    public function appSlug(?string $app, string $uri, array|string|null $action = null): string
    {
        if (is_array($action)) {
            $action = $action[0] ?? null;
        }

        $action = is_string($action) && $action !== '' ? $action : 'index';

        $base = trim($uri, '/').'.'.$action;

        if ($app !== null) {
            return $app.'.'.$base;
        }

        $apps = app(ThinkApplication::class);

        return $apps->enabled() ? $apps->current().'.'.$base : $base;
    }

    /**
     * 把当前所有 Resource 的权限节点写进库 —— 与 Laravel 侧同一套语义。
     *
     * ## 为什么必须有这个
     *
     * ThinkPHP 驱动原先**没有**同步权限节点的入口（没有 sync、也没有
     * `aimanong:permission` 命令）。后果不是「少个命令」，而是
     * **RBAC 在 TP 上实际不可用**：节点永远建不出来，于是除超管以外
     * 所有人访问任何页面都是 403 —— 「权限系统生效了」与「谁都进不去」
     * 在测试里长得一模一样（e2e 只断言了普通角色 403，所以一直是绿的）。
     *
     * ## 与 Laravel 一致的两点
     *
     * ① 多应用时按「应用 × 属于它的 Resource」展开（否则 `/agent`
     *    后台没有任何节点可分配，而 slug() 查的是 `agent.orders.index`）。
     * ② 孤儿节点**保留不删** —— 那是用户已配给角色的授权，误删比留着更糟。
     *    只回报数量，让人自己决定。
     *
     * @param  string|null  $forApp  只为某应用建节点；null = 按当前上下文
     * @return array{created: int, existing: int, orphaned: int}
     */
    public function sync(?string $forApp = null): array
    {
        $created = 0;
        $existing = 0;
        $expected = [];

        $compiler = new Compiler;
        $apps = app(ThinkApplication::class);

        if ($forApp !== null) {
            $appList = [$forApp];
        } else {
            $appList = $apps->enabled() ? $apps->names() : [null];
        }

        foreach (Aimanong::registry()->all() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            try {
                $node = $compiler->compile($class);
            } catch (\Throwable) {
                // 声明有误的 Resource 跳过，不让整个同步崩掉
                continue;
            }

            $uri = $node->uri;
            $label = $node->label;

            foreach ($appList as $app) {
                if ($app !== null && ! Aimanong::registry()->belongsTo($uri, $app)) {
                    continue;
                }

                foreach (PermissionNodes::actionsFor($node) as $action) {
                    $slug = $this->appSlug($app, $uri, $action);

                    $expected[$slug] = true;

                    if (Permission::where('slug', $slug)->find() !== null) {
                        $existing++;

                        continue;
                    }

                    $perm = new Permission;
                    $perm->save([
                        'slug' => $slug,
                        // 自定义动作的标签由声明方提供
                        'name' => $label.' · '.PermissionNodes::labelFor($node, $action)
                            .($app !== null ? '（'.$apps->title($app).'）' : ''),
                        'group' => $app !== null ? $apps->title($app).' / '.$label : $label,
                    ]);

                    $created++;
                }
            }
        }

        $orphaned = Permission::whereNotIn('slug', array_keys($expected))->count();

        return ['created' => $created, 'existing' => $existing, 'orphaned' => $orphaned];
    }
}
