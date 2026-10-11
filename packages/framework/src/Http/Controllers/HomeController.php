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
        ]);
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
