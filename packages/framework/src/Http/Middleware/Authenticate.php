<?php

declare(strict_types=1);

namespace Aimanong\Http\Middleware;

use Aimanong\Aimanong;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    /**
     * 未登录处理。
     *
     * 注意：Laravel 11+ 起 AuthenticationException::redirectTo()
     * 必须接收 Request 实例，此处不依赖该行为，直接手工跳转。
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        // 多应用：支持 admin.auth:merchant 这样指定 guard
        $guard ??= Aimanong::context()->guard();

        $resolved = auth()->guard($guard);

        if ($resolved->user() !== null || $this->shouldPassThrough($request)) {
            return $next($request);
        }

        $loginPath = Aimanong::url('auth/login');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'login' => $loginPath,
            ], 401);
        }

        return redirect()->guest($loginPath);
    }

    protected function shouldPassThrough(Request $request): bool
    {
        $except = config('aimanong.auth.except', []);

        if (! is_array($except)) {
            return false;
        }

        $path = trim($request->path(), '/');

        /** @var array<int, mixed> $except */
        foreach ($except as $route) {
            if (! is_string($route)) {
                continue;
            }

            $route = trim(Aimanong::url($route), '/');

            if ($route === $path || str_starts_with($path, $route.'/')) {
                return true;
            }
        }

        return false;
    }
}
