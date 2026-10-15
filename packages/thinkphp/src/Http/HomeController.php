<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http;

use Aimanong\Aimanong;
use Aimanong\Contracts\Permissible;
use Aimanong\Menu\MenuRegistry;
use Aimanong\Support\Drivers;
use Aimanong\ThinkPHP\Models\Administrator;
use Aimanong\ThinkPHP\Models\Permission;
use Aimanong\ThinkPHP\ThinkPermissionChecker;
use Aimanong\ThinkPHP\View\NullErrors;
use Aimanong\ThinkPHP\View\ThinkView;
use think\Response;

/**
 * 控制台首页（工作台）。
 *
 * 与 Laravel 侧的 `HomeController::index()` 提供**同一组数据** ——
 * 模板是同一份真源转出来的，数据形状不一致就会渲染出错。
 *
 * ## 与 Laravel 侧的差异（如实说明）
 *
 * | 数据 | Laravel | ThinkPHP |
 * |---|---|---|
 * | user / roles / permCount / isSuper | 当前 guard 的用户 | **相同**（按当前后台的用户模型） |
 * | ui（主题偏好） | 存在用户偏好里 | 用默认主题 |
 * | resourceCount | 注册表 | 相同（core 的 Registry） |
 * | quickLinks | 按权限过滤的菜单 | 相同（core 的 MenuRegistry + 权限驱动） |
 *
 * ⚠️ 2026-10-15 修：此前这里 `roles` / `permCount` / `isSuper` / `rbacEnabled`
 * 是**硬编码的空值**（那段注释还写着「未实现认证」—— 早已不是事实）。
 * 后果是**超级管理员登录后，控制台告诉他「你还未被分配任何权限」**。
 * 现在按当前后台的用户模型真实计算。
 *
 * 多应用下「当前后台」由 `ApplicationResolver` 决定，用户模型也随之不同 ——
 * 所以这里判的是 `Permissible` 契约，不是具体类。
 */
final class HomeController
{
    public function index(): Response
    {
        $menuRegistry = new MenuRegistry;

        $quickLinks = [];

        foreach ($menuRegistry->visible() as $item) {
            $quickLinks[] = [
                'uri' => $item->uri,
                'label' => $item->label,
                'icon' => $item->icon,
            ];
        }

        $user = Aimanong::user();

        $roles = [];
        $permCount = 0;
        $isSuper = false;

        if ($user instanceof Permissible) {
            $isSuper = $user->isSuper();

            /*
             * `roles` **不在 `Permissible` 契约里** —— 契约只约定
             * 权限判定那三个方法。所以这里必须判方法存在，
             * 否则一个实现了契约、但没有 roles 关系的模型会直接抛异常。
             */
            if (method_exists($user, 'roles')) {
                foreach ($user->roles as $role) {
                    $roles[] = (string) $role->getAttr('name');
                    $permCount += $role->permissions()->count();
                }
            }

            // 超管：按「全部节点数」展示，否则会显示成「0 项权限」
            if ($isSuper) {
                $permCount = Permission::count();
            }
        }

        /*
         * 用户数按**当前后台的用户模型**统计。
         *
         * 多应用下各后台有自己的用户表 —— 写死 `Administrator::count()`
         * 会在代理商后台显示管理员的数量。
         */
        $apps = Drivers::application();
        $modelClass = $apps->enabled() ? $apps->model($apps->current()) : Administrator::class;
        $userCount = class_exists($modelClass) ? $modelClass::count() : 0;

        $html = (new ThinkView)->render('index', [
            'user' => $user,
            'resourceCount' => Aimanong::registry()->count(),
            'menu' => $menuRegistry->tree(),
            'quickLinks' => $quickLinks,
            'roles' => $roles,
            'permCount' => $permCount,
            'isSuper' => $isSuper,
            'userCount' => $userCount,
            'rbacEnabled' => (new ThinkPermissionChecker)->rbacEnabled(),
            'ui' => $this->defaultUi(),
            'errors' => new NullErrors,
            'menuRegistry' => $menuRegistry,
        ]);

        return Response::create($html, 'html', 200);
    }

    /**
     * 默认主题偏好。
     *
     * Laravel 侧从用户的 preferences 取；TP 侧认证还没做，
     * 所以先用默认值 —— 界面能正常渲染，只是没有个性化。
     *
     * @return array<string, mixed>
     */
    protected function defaultUi(): array
    {
        return [
            'primary' => '#2563eb',
            'radius' => 'md',
            'density' => 'normal',
            'dark' => false,
            'sidebar' => 'expanded',
        ];
    }
}
