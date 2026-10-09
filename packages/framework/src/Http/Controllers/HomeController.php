<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Aimanong\Auth\PermissionGate;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('aimanong::index', [
            'user' => Aimanong::user(),
            'resourceCount' => Aimanong::registry()->count(),
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
            abort(403, '没有权限访问「'.($class::label() ?? $uri).'」。'
                .'请让管理员分配「查看列表」权限。');
        }

        $node = (new Compiler)->compile($class);
        $schema = (new JsonSchemaEmitter)->emit($node);

        return view('aimanong::resource', [
            'uri' => $uri,
            'label' => $node->label,
            'user' => Aimanong::user(),
            'schema' => $schema,
        ]);
    }
}
