<?php

declare(strict_types=1);

namespace Aimanong\Http\Middleware;

use Aimanong\Application\ApplicationManager;
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
         * 多应用下 config('aimanong.route.prefix') 会被最后一次 switch() 覆盖。
         * 若直接用它设置 session.path，admin 请求会拿到 merchant 的 path，
         * cookie 写错位置 → CSRF 419、登录态丢失。
         *
         * 因此从当前请求路径推断真实前缀。
         */
        $prefix = $this->resolvePrefix($request);

        config(['session.path' => '/'.$prefix]);

        $domain = config('aimanong.route.domain');
        if (is_string($domain) && $domain !== '') {
            config(['session.domain' => $domain]);
        }

        return $next($request);
    }

    /**
     * 推断当前请求所属后台的前缀。
     */
    protected function resolvePrefix(Request $request): string
    {
        $fallback = config('aimanong.route.prefix');
        $fallback = is_string($fallback) ? trim($fallback, '/') : 'admin';

        try {
            /** @var ApplicationManager $apps */
            $apps = app('aimanong.application');

            if (! $apps->enabled()) {
                return $fallback;
            }

            $path = trim($request->path(), '/');
            $names = $apps->names();

            // 长前缀优先，避免 admin 误匹配 admin-x
            usort($names, fn ($a, $b): int => strlen($b) <=> strlen($a));

            foreach ($names as $name) {
                $p = $apps->prefix($name);

                if ($path === $p || str_starts_with($path, $p.'/')) {
                    return $p;
                }
            }
        } catch (\Throwable) {
            // 无容器/无应用配置时回退
        }

        return $fallback;
    }
}
