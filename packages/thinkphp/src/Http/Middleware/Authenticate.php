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
        if ($this->guard()->check() || $this->shouldPassThrough($request)) {
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

    /**
     * 当前后台的会话守卫。
     *
     * ⚠️ **必须走运行时**（`Aimanong::guard()`），不能 `new ThinkGuard`。
     *
     * 多应用下 session key 带应用维度（`<base>_<app>`），而
     * `new ThinkGuard` 用的是**默认** key —— 于是「登录写默认 key、
     * 读取查带应用的 key」，两边对不上：**登录成功但立刻 403/掉线**。
     * 这个 bug 实测踩到过（配了多应用后所有接口 403）。
     */
    protected function guard(): ThinkGuard
    {
        /** @var ThinkGuard $guard */
        $guard = Aimanong::guard();

        return $guard;
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
            || str_contains($this->acceptHeader($request), 'application/json');
    }

    /**
     * 取 Accept 头为字符串。
     *
     * ⚠️ `$request->header()` 的返回类型是 `array|string|null` ——
     * 直接 `(string)` 转换在拿到数组时是错的（PHPStan 抓到的）。
     */
    protected function acceptHeader(Request $request): string
    {
        $value = $request->header('accept', '');

        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) ? $value : '';
    }
}
