<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Aimanong\Auth\PermissionGate;
use Aimanong\Menu\MenuRegistry;
use Aimanong\Models\Administrator;
use Aimanong\Models\Permission;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
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
        ]);
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
        ]);
    }
}
