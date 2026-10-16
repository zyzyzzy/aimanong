<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers\Ai;

use Aimanong\Ai\Introspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 自省 API（Laravel 驱动层）。
 *
 * 让 AI 直接询问"你能做什么"，而不是读文档猜。
 *
 * ## 这个类现在只做三件事
 *
 *   1. 取参数
 *   2. 调 `Aimanong\Ai\Introspection`（**在 core，两个驱动共用**）
 *   3. 把 `['status','body']` 包成 JSON 响应
 *
 * 业务逻辑一行都不在这里 —— 否则 ThinkPHP 要再抄一份 433 行，
 * 两份拷贝必然漂移（本项目最贵的几类 bug 全是这么来的）。
 *
 * 安全：默认仅 local / debug 开启；生产需配置
 * AIMANONG_AI_TOKEN 并在请求中携带。
 */
class IntrospectionController extends Controller
{
    protected Introspection $introspection;

    public function __construct()
    {
        $this->middleware = [];
        $this->introspection = new Introspection;

        $this->guard();
    }

    /**
     * 全部能力清单。
     */
    public function capabilities(): JsonResponse
    {
        return $this->respond($this->introspection->capabilities());
    }

    /**
     * 某个 Resource 的完整 Schema。
     */
    public function schema(Request $request, string $uri): JsonResponse
    {
        return $this->respond($this->introspection->schema($uri));
    }

    /**
     * OpenAPI 全量文档。
     */
    public function openapi(): JsonResponse
    {
        return $this->respond($this->introspection->openapi());
    }

    /**
     * 给 AI 读的上下文（Markdown）。
     */
    public function context(): JsonResponse
    {
        return $this->respond($this->introspection->context());
    }

    /**
     * 校验一段 Resource 声明是否合法。
     *
     * 这是 AI 的自检入口：生成后先来验证，不必等人发现。
     */
    public function verify(Request $request): JsonResponse
    {
        return $this->respond($this->introspection->verify($request->input('resource')));
    }

    /**
     * 已声明的菜单树（全量，不做权限过滤）。
     */
    public function menu(): JsonResponse
    {
        return $this->respond($this->introspection->menu());
    }

    /**
     * 可复制代码片段。
     */
    public function examples(string $pattern): JsonResponse
    {
        return $this->respond($this->introspection->examples($pattern));
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    protected function respond(array $result): JsonResponse
    {
        return response()->json($result['body'], $result['status']);
    }

    /**
     * 安全守卫：生产环境必须带 token。
     */
    protected function guard(): void
    {
        $enabled = config('aimanong.ai.enable');
        $isLocal = app()->environment('local');

        // 未显式配置时：local/debug 开启，其它环境关闭
        $allowed = $enabled === null
            ? ($isLocal || (bool) config('app.debug'))
            : (bool) $enabled;

        if (! $allowed) {
            abort(404);
        }

        // 生产环境需要 token
        if (! $isLocal) {
            $expected = config('aimanong.ai.token');

            $header = request()->header('X-Aimanong-Token');
            $query = request()->query('token');

            $given = is_string($header) ? $header : (is_string($query) ? $query : '');

            if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $given)) {
                abort(401, 'Aimanong AI 接口需要有效 token');
            }
        }
    }
}
