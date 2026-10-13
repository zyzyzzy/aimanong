<?php

declare(strict_types=1);

namespace Aimanong\Http\Middleware;

use Aimanong\Aimanong;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台引导中间件：注入主题、语言、当前管理员等上下文。
 *
 * Laravel 12 提供 Context 门面做请求级上下文，此处直接使用。
 */
class Bootstrap
{
    public function handle(Request $request, Closure $next): Response
    {
        Aimanong::bootstrap();

        $user = Aimanong::guard()->user();
        if ($user !== null) {
            Context::add('aimanong.user_id', $user->getAuthIdentifier());
        }

        return $next($request);
    }
}
