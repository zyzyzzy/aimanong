<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Audit;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 操作日志中间件。
 *
 * 挂在 `admin` 中间件组的**最后**（此时已认证，拿得到当前用户），
 * 包住整个请求以统计耗时，并在响应返回后落库。
 */
class RecordOperation
{
    public function __construct(protected AuditRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        // 耗时统计失败也不能影响响应
        try {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $this->recorder->recordOperation($request, $response, $duration);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }
}
