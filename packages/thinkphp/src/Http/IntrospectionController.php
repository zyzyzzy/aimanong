<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http;

use Aimanong\Ai\Introspection;
use think\Request;
use think\Response;

/**
 * 自省 API（ThinkPHP 驱动层）。
 *
 * 与 Laravel 侧**同一份实现** —— 逻辑全在 core 的
 * `Aimanong\Ai\Introspection`，这里只做「取参 → 调用 → 包成 JSON」。
 *
 * ## 为什么必须有这一份
 *
 * 在此之前 TP 侧**完全没有** `/__ai/*`。后果不是「少个便利接口」：
 *
 *   · `llms.txt` 与 `capabilities.json` 是**跨驱动**的 AI 资料，
 *     它们教 AI「先查 /__ai/capabilities.json」——
 *     TP 用户照做拿到 404，只能回去猜 API
 *   · 宪法①「AI 优先」在多框架下直接失效：装了 TP 的用户没有自省入口
 *   · AGENTS.md 的「先用自省接口」流程在 TP 上是空话
 *
 * ## 安全
 *
 * 与 Laravel 侧同一套语义：默认仅 local/debug 开启；
 * 生产需配置 `aimanong.ai.token` 并带 `X-Aimanong-Token` 头。
 * 两端**状态码也要一致**（关掉时 404、缺 token 时 401），
 * 否则「同一份声明两端行为一致」又是一句空话。
 */
final class IntrospectionController
{
    protected Introspection $introspection;

    public function __construct()
    {
        $this->introspection = new Introspection;
    }

    public function capabilities(Request $request): Response
    {
        return $this->respond($request, $this->introspection->capabilities());
    }

    public function schema(Request $request, string $uri): Response
    {
        return $this->respond($request, $this->introspection->schema($uri));
    }

    public function openapi(Request $request): Response
    {
        return $this->respond($request, $this->introspection->openapi());
    }

    public function context(Request $request): Response
    {
        return $this->respond($request, $this->introspection->context());
    }

    public function verify(Request $request): Response
    {
        return $this->respond($request, $this->introspection->verify($request->param('resource')));
    }

    public function menu(Request $request): Response
    {
        return $this->respond($request, $this->introspection->menu());
    }

    public function examples(Request $request, string $pattern): Response
    {
        return $this->respond($request, $this->introspection->examples($pattern));
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    protected function respond(Request $request, array $result): Response
    {
        if (($deny = $this->deny()) !== null) {
            return $deny;
        }

        return json($result['body'], $result['status']);
    }

    /**
     * 安全守卫 —— 与 Laravel 侧 `IntrospectionController::guard()` 同语义。
     *
     * 返回 null 表示放行。
     */
    protected function deny(): ?Response
    {
        $enabled = config('aimanong.ai.enable');
        $isLocal = $this->isLocal();

        $allowed = $enabled === null
            ? ($isLocal || (bool) config('app.app_debug'))
            : (bool) $enabled;

        if (! $allowed) {
            // 与 Laravel 的 abort(404) 一致：不暴露「这里有个接口」
            return Response::create('', 'html', 404);
        }

        if (! $isLocal) {
            $expected = config('aimanong.ai.token');

            $given = (string) (\request()->header('X-Aimanong-Token')
                ?? \request()->param('token')
                ?? '');

            if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $given)) {
                return json(['message' => 'Aimanong AI 接口需要有效 token'], 401);
            }
        }

        return null;
    }

    protected function isLocal(): bool
    {
        /**
         * TP 没有 `app()->environment()`。
         *
         * 判定「本地」用 `app_debug` + 一个可显式覆盖的配置 ——
         * 与 Laravel 侧「local 环境」的意图对齐：**开发期默认开、生产默认关**。
         */
        return (bool) config('app.app_debug');
    }
}
