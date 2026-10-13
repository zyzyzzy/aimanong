<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http\Middleware;

use Aimanong\Aimanong;
use Aimanong\ThinkPHP\Auth\ThinkGuard;
use think\Request;
use think\Response;

/**
 * 未登录拦截。
 *
 * ## 例外路径
 *
 * 登录页本身必须放行 —— 否则会无限重定向
 * （跳登录页 → 登录页也要登录 → 再跳登录页…）。
 * 这类问题在浏览器里表现为「页面一直转圈」，排查成本很高。
 */
final class Authenticate
{
    /**
     * 免登录路径（相对于后台前缀）。
     *
     * @var array<int, string>
     */
    public const PASS_THROUGH = ['auth/login', 'auth/logout', 'assets/'];

    public function handle(Request $request, \Closure $next): Response
    {
        if ((new ThinkGuard)->check() || $this->shouldPassThrough($request)) {
            return $next($request);
        }

        $loginPath = Aimanong::url('auth/login');

        if ($this->wantsJson($request)) {
            return Response::create([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => '请先登录。'],
                'login' => $loginPath,
            ], 'json', 401);
        }

        return redirect($loginPath);
    }

    protected function shouldPassThrough(Request $request): bool
    {
        $path = trim($request->pathinfo(), '/');
        $prefix = trim(Aimanong::url(''), '/');

        // 去掉后台前缀，只比较前缀内的相对路径
        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = trim(substr($path, strlen($prefix)), '/');
        }

        foreach (self::PASS_THROUGH as $except) {
            if ($path === rtrim($except, '/') || str_starts_with($path, $except)) {
                return true;
            }
        }

        return false;
    }

    protected function wantsJson(Request $request): bool
    {
        return $request->isAjax()
            || str_contains((string) $request->header('accept', ''), 'application/json');
    }
}
