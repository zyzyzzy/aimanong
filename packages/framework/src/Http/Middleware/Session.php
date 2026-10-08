<?php

declare(strict_types=1);

namespace Aimanong\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台 session 隔离。
 *
 * 让后台使用独立的 session path，与前台互不干扰。
 */
class Session
{
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = config('aimanong.route.prefix');
        $prefix = is_string($prefix) ? trim($prefix, '/') : 'admin';

        config(['session.path' => '/'.$prefix]);

        $domain = config('aimanong.route.domain');
        if (is_string($domain) && $domain !== '') {
            config(['session.domain' => $domain]);
        }

        return $next($request);
    }
}
