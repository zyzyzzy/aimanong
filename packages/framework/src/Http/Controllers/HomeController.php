<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class HomeController extends Controller
{
    public function index(): \Illuminate\View\View
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
    public function resource(Request $request, string $uri): \Illuminate\View\View
    {
        $class = Aimanong::registry()->find($uri);

        if ($class === null) {
            abort(404, "Resource [{$uri}] 未注册");
        }

        $node = (new Compiler())->compile($class);
        $schema = (new JsonSchemaEmitter())->emit($node);

        return view('aimanong::resource', [
            'uri' => $uri,
            'label' => $node->label,
            'user' => Aimanong::user(),
            'schema' => $schema,
        ]);
    }
}
