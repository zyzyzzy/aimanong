<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http;

use Aimanong\Aimanong;
use Aimanong\Contracts\Permissible;
use Aimanong\Menu\MenuRegistry;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Aimanong\Support\Drivers;
use Aimanong\Support\FieldType;
use Aimanong\ThinkPHP\Models\Administrator;
use Aimanong\ThinkPHP\Models\Permission;
use Aimanong\ThinkPHP\ThinkPermissionChecker;
use Aimanong\ThinkPHP\View\NullErrors;
use Aimanong\ThinkPHP\View\ThinkView;
use think\exception\HttpException;
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
     * 资源页面（HTML）。
     *
     * 与 Laravel 侧 `HomeController::resource()` 提供同一组数据 ——
     * 模板是从同一份 Blade 真源转出来的，数据形状必须一致。
     *
     * ## 路由位置
     *
     * 这是**兜底路由**，必须放在所有具体路由（`/`、`/api/*`、`/auth/*`、
     * `/profile`）**之后**。否则它会吞掉 `/api/customers` 这类请求。
     *
     * ## 与 Laravel 侧的差异
     *
     * | 数据 | Laravel | ThinkPHP |
     * |---|---|---|
     * | pluginFieldViews | Extension 注册的渲染片段 | **空数组**（TP 暂无扩展机制） |
     * | pluginScripts | FieldType::fieldScripts() | 同上（core 共享，但无插件注册） |
     * | ui | 用户偏好 | 默认值 |
     */
    public function resource(string $uri): Response
    {
        $class = Aimanong::registry()->find($uri);

        if ($class === null) {
            throw new HttpException(404, "Resource [{$uri}] 未注册");
        }

        /*
         * ⚠️ 归属校验必须在权限校验之前（返回 404 而不是 403）。
         *
         * 403 等于告诉对方「这个资源存在，只是你没权限」——
         * 那是把后台结构泄露出去。这条规则写在 core 契约里，
         * 两端都必须遵守（Laravel 侧 authorize_() 第一行也是这个检查）。
         */
        $apps = Drivers::application();

        if ($apps->enabled() && ! Aimanong::registry()->belongsTo($uri, $apps->current())) {
            throw new HttpException(404, "Resource [{$uri}] 未注册");
        }

        // 权限判定：未授权的用户应看到明确的提示，而不是空表格
        $checker = new ThinkPermissionChecker;

        if ($checker->rbacEnabled()) {
            $slug = $checker->slug($uri, 'index');

            if (! $checker->check($slug)) {
                throw new HttpException(
                    403,
                    '没有权限访问「'.$class::label().'」。请让管理员分配「查看列表」权限。'
                );
            }
        }

        $node = (new Compiler)->compile($class);
        $schema = (new JsonSchemaEmitter)->emit($node);

        $html = (new ThinkView)->render('resource', [
            'uri' => $uri,
            'label' => $node->label,
            'user' => Aimanong::user(),
            'schema' => $schema,
            'menu' => (new MenuRegistry)->tree(),
            'ui' => $this->defaultUi(),
            'errors' => new NullErrors,
            // TP 暂无扩展机制，这两个传空数组
            // （core 的 FieldType 是共享的，但没有插件注册过 fieldView/fieldScript）
            'pluginFieldViews' => [],
            'pluginScripts' => [],
            /*
             * 按钮级权限 —— 与 Laravel 侧同一套语义。
             *
             * 没有权限的按钮**服务端就不渲染**（不是 CSS/JS 藏起来）。
             * 两端必须一致：同一份声明、同一组权限节点，页面就该长得一样。
             */
            'abilities' => $this->abilities($uri),
        ]);

        return Response::create($html, 'html', 200);
    }

    /**
     * 当前用户对这个 Resource 能做哪些动作。
     *
     * RBAC 关闭时全为 true —— 「没启用权限系统」不该让按钮全消失。
     *
     * @return array{create: bool, update: bool, destroy: bool}
     */
    protected function abilities(string $uri): array
    {
        $checker = new ThinkPermissionChecker;

        if (! $checker->rbacEnabled()) {
            return ['create' => true, 'update' => true, 'destroy' => true];
        }

        $can = static fn (string $action): bool => $checker->check($checker->slug($uri, $action));

        return [
            'create' => $can('create'),
            'update' => $can('update'),
            'destroy' => $can('destroy'),
        ];
    }

    /**
     * 默认主题偏好。
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
