<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Aimanong\Auth\PermissionGate;
use Aimanong\Menu\MenuRegistry;
use Aimanong\Models\Administrator;
use Aimanong\Models\Permission;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Aimanong\Support\Drivers;
use Aimanong\Support\FieldType;
use Aimanong\Ui\ThemeConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class HomeController extends Controller
{
    public function index(): View
    {
        $user = Aimanong::user();
        $menuRegistry = new MenuRegistry;

        /*
         * 工作台数据：
         *   - 快捷入口：与侧边栏同源的可见菜单（按权限过滤）
         *   - 当前身份：角色 / 权限数，让用户知道自己能做什么
         */
        $quickLinks = [];

        foreach ($menuRegistry->visible() as $item) {
            $quickLinks[] = [
                'uri' => $item->uri,
                'label' => $item->label,
                'icon' => $item->icon,
            ];
        }

        $roles = [];
        $permCount = 0;
        $isSuper = false;

        if ($user instanceof Administrator) {
            $isSuper = $user->isSuper();

            foreach ($user->roles as $role) {
                $roles[] = $role->name;
                $permCount += $role->permissions()->count();
            }

            if ($isSuper) {
                $permCount = Permission::query()->count();
            }
        }

        return view('aimanong::index', [
            'user' => $user,
            'resourceCount' => Aimanong::registry()->count(),
            'menu' => $menuRegistry->tree(),
            'quickLinks' => $quickLinks,
            'roles' => $roles,
            'permCount' => $permCount,
            'isSuper' => $isSuper,
            'userCount' => Administrator::query()->count(),
            'rbacEnabled' => PermissionGate::enabled(),
            'ui' => $this->uiPreferences(),
        ]);
    }

    /**
     * 当前用户的界面偏好。
     *
     * 服务端渲染时就注入，避免深色用户看到白色闪屏。
     *
     * @return array<string, mixed>
     */
    protected function uiPreferences(): array
    {
        $user = Aimanong::user();

        if (! $user instanceof Administrator || ! is_array($user->preferences)) {
            return ThemeConfig::defaults();
        }

        return ThemeConfig::normalize($user->preferences);
    }

    /**
     * Resource 页面：返回 Vue SPA 外壳，并把 Schema 注入前端。
     *
     * 页面本身不含任何字段硬编码 —— 表格长什么样由 schema.json 决定。
     */
    public function resource(Request $request, string $uri): View
    {
        $class = Aimanong::registry()->find($uri);

        if ($class === null) {
            abort(404, "Resource [{$uri}] 未注册");
        }

        /*
         * 🔴 多应用的**归属校验必须排在最前**（返回 404 而不是 403）。
         *
         * 审计发现（2026-10-16）：`ResourceController::authorize_()` 有这一步，
         * 但**页面路由**（本方法）漏了 —— 于是多应用隔离在页面上失效：
         *
         *     GET /agent/admin-roles   （该 Resource 只属于 admin）
         *     → 403「没有权限访问『角色』」
         *
         * 403 等于告诉对方「这个资源**存在**，只是你没权限」——
         * 把总后台的结构泄露给了代理商后台。
         * API 侧返回的是正确的 404，两端行为不一致。
         *
         * TP 侧的 HomeController 本来就有这个检查（那边先踩到），
         * 说明这类「两端各写一遍、同一类 bug 各犯一遍」需要对称修。
         */
        $apps = Drivers::application();

        if ($apps->enabled() && ! Aimanong::registry()->belongsTo($uri, $apps->current())) {
            abort(404, "Resource [{$uri}] 未注册");
        }

        /*
         * 页面路由也要做权限判定 ——
         * 只拦 API 会导致：未授权用户打开页面看到「暂无数据」的空表格，
         * 而不是明确的「无权限」提示（v1.3.0 实测发现的体验缺陷）。
         *
         * 数据本身是安全的（API 403），但体验上应直接告知。
         */
        if (! PermissionGate::check(PermissionGate::slug($uri, 'index'))) {
            abort(403, '没有权限访问「'.$class::label().'」。'
                .'请让管理员分配「查看列表」权限。');
        }

        $node = (new Compiler)->compile($class);
        $schema = (new JsonSchemaEmitter)->emit($node);

        return view('aimanong::resource', [
            'uri' => $uri,
            'label' => $node->label,
            'user' => Aimanong::user(),
            'schema' => $schema,
            // 菜单（按当前用户权限过滤）
            'menu' => (new MenuRegistry)->tree(),
            'ui' => $this->uiPreferences(),
            /*
             * 插件字段的渲染片段（由 Extension::fieldView() 注册）。
             *
             * 框架核心**不再内置任何插件字段的 UI** ——
             * 页面按字段类型把渲染分发给对应的插件片段。
             */
            'pluginFieldViews' => $this->pluginFieldViews($node),
            /*
             * 插件字段的 JS 片段（由 Extension::fieldScript() 注册）。
             * 在 Vue setup() 作用域内渲染，可用 ref / computed / form。
             */
            'pluginScripts' => FieldType::fieldScripts(),
            /*
             * 按钮级权限。
             *
             * 实测：在此之前框架**没有**按钮级权限（自省接口里就写着
             * 「按钮级/字段级权限需自行实现」）。后果是一个 LLM 为了做
             * 「没有删除权限就看不到删除按钮」，自己写了一个中间件去
             * 正则匹配 `@click="remove(row)"` 再从响应 HTML 里把按钮抠掉。
             *
             * 这里把它变成框架默认行为：权限节点 `{uri}.{action}` 框架本来
             * 就会生成，按钮直接按它决定要不要渲染。
             * **服务端不渲染**（而不是 CSS/JS 藏起来）—— 按钮根本不进 HTML。
             */
            'abilities' => $this->abilities($uri),
        ]);
    }

    /**
     * 当前用户对这个 Resource 能做哪些动作。
     *
     * RBAC 关闭时全为 true（与 PermissionGate::check 的行为一致）——
     * 「没启用权限系统」不该导致按钮全消失。
     *
     * @return array{create: bool, update: bool, destroy: bool, show: bool}
     */
    protected function abilities(string $uri): array
    {
        $can = static fn (string $action): bool => PermissionGate::check(
            PermissionGate::slug($uri, $action)
        );

        return [
            'create' => $can('create'),
            'update' => $can('update'),
            'destroy' => $can('destroy'),
            /*
             * 详情页权限。
             *
             * ⚠️ 必须与 show 接口（GET /api/{uri}/{id}）用**同一个权限节点** ——
             * 否则会出现「按钮能点、点了 403」这种自相矛盾的界面，
             * 而那正是本项目反复在修的「静默失效」的近亲。
             */
            'show' => $can('show'),
        ];
    }

    /**
     * 当前 Resource 用到的**插件字段渲染片段**。
     *
     * 只返回这个页面真正会用到的（按 schema 里出现的字段类型过滤），
     * 避免把无关插件的片段也塞进页面。
     *
     * @return array<string, string> 字段类型 => blade 视图名
     */
    protected function pluginFieldViews(ResourceNode $node): array
    {
        $out = [];

        foreach ($node->fields as $field) {
            $view = FieldType::fieldViewFor($field->type);

            if ($view !== null && ! isset($out[$field->type])) {
                $out[$field->type] = $view;
            }
        }

        return $out;
    }
}
