<?php

declare(strict_types=1);

namespace Aimanong\Http\Middleware;

use Aimanong\Aimanong;
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
        /*
         * 全部通过 context 获取 —— 多应用 switch() 会改写全局 config，
         * 直接读会让 admin 请求拿到 merchant 的值
         * （cookie path 写错 → CSRF 419、登录态丢失）。
         */
        $context = Aimanong::context();

        config(['session.path' => '/'.$context->prefix()]);

        $domain = $context->domain();

        if ($domain !== null) {
            config(['session.domain' => $domain]);
        }

        return $next($request);
    }
}
