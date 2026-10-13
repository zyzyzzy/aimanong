<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Audit;

use Aimanong\Aimanong;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 审计写入器 —— 操作日志与登录日志的**唯一**写入口。
 *
 * ## 为什么要有这个类
 *
 * 竞品（若依 / Jeecg / yudao）普遍是「注解 + AOP」各写各的，
 * 同一个动作可能被记成两遍，或者一处漏记。
 * Aimanong 只允许一个写入口，保证：
 *   - 字段口径统一（IP、UA、脱敏规则只有一份）
 *   - 关掉开关时**两处一起关**（不会有半开状态）
 *
 * ## 不阻塞业务是硬约束
 *
 * 记日志失败（表不存在、磁盘满、字段超长）**绝不能让业务请求 500**。
 * 因此所有写入都包在 try/catch 里，失败只写 Laravel 日志。
 */
class AuditRecorder
{
    /**
     * 请求体里这些键一律掩码，不落库。
     *
     * 用「包含匹配」而非全等：password_confirmation / new_password 也要盖住。
     *
     * @var array<int, string>
     */
    protected const SENSITIVE = [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'api_key',
        'access_key',
    ];

    /** 单条日志的请求体上限（字符），超出截断，避免 json 字段爆掉 */
    protected const MAX_PAYLOAD_CHARS = 8000;

    public function operationEnabled(): bool
    {
        return (bool) config('aimanong.foundation.operation_log.enable', true);
    }

    public function loginEnabled(): bool
    {
        return (bool) config('aimanong.foundation.login_log.enable', true);
    }

    /**
     * 记录一次写操作。
     *
     * 只记「会改变数据」的请求：GET / HEAD / OPTIONS 一律跳过 ——
     * 否则列表页刷新一下就是一条日志，日志表会被撑爆，
     * 真正重要的「谁改了这条数据」会被淹没。
     */
    public function recordOperation(Request $request, Response $response, int $durationMs): void
    {
        if (! $this->operationEnabled() || ! $this->isWritable($request)) {
            return;
        }

        try {
            $route = $this->resolveRoute($request);

            OperationLog::query()->create([
                'user_id' => $this->userId($request),
                'username' => $this->username($request),
                'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 500),
                'resource_uri' => $route['uri'],
                'action' => $route['action'],
                'target_id' => $route['target'],
                'status' => $response->getStatusCode(),
                'ip' => $this->ip($request),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'payload' => $this->sanitize($request),
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            // 审计失败不能影响业务
            report($e);
        }
    }

    /**
     * 记录一次登录尝试（成功 / 失败）。
     */
    public function recordLogin(
        ?string $username,
        bool $success,
        ?int $userId = null,
        ?string $reason = null,
        ?Request $request = null
    ): void {
        if (! $this->loginEnabled()) {
            return;
        }

        $request ??= request();

        try {
            LoginLog::query()->create([
                'user_id' => $userId,
                'username' => $username === null ? null : mb_substr($username, 0, 190),
                'status' => $success ? LoginLog::STATUS_SUCCESS : LoginLog::STATUS_FAILED,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 190),
                'ip' => $this->ip($request),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * 是否是需要审计的请求。
     */
    protected function isWritable(Request $request): bool
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $path = trim($request->path(), '/');
        $except = config('aimanong.foundation.operation_log.except', []);

        if (is_array($except)) {
            foreach ($except as $pattern) {
                if (! is_string($pattern)) {
                    continue;
                }

                $pattern = trim(Aimanong::url($pattern), '/');

                if ($pattern === $path || str_starts_with($path, $pattern.'/')) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 从请求路径推断「操作了哪个 Resource 的哪个动作」。
     *
     * 路径形态（prefix 已由 Aimanong::url 处理）：
     *   admin/api/{uri}                POST   → store
     *   admin/api/{uri}/{id}           PUT    → update
     *   admin/api/{uri}/{id}           DELETE → destroy
     *   admin/api/{uri}/{id}/move      PUT    → move
     *
     * @return array{uri: ?string, action: ?string, target: ?string}
     */
    protected function resolveRoute(Request $request): array
    {
        $segments = explode('/', trim($request->path(), '/'));
        $apiAt = array_search('api', $segments, true);

        if ($apiAt === false || ! isset($segments[$apiAt + 1])) {
            // 非 Resource 接口（登录、退出、界面偏好等）单独归类，
            // 否则 action 为 null，列表里会出现一个没有文字的空徽章。
            $action = match (end($segments)) {
                'login' => 'login',
                'logout' => 'logout',
                default => null,
            };

            return ['uri' => null, 'action' => $action, 'target' => null];
        }

        $uri = $segments[$apiAt + 1];
        $rest = array_slice($segments, $apiAt + 2);

        // 已知的静态子路径
        if ($rest !== [] && in_array($rest[0], ['export', 'tree'], true)) {
            return ['uri' => $uri, 'action' => $rest[0], 'target' => null];
        }

        $target = $rest[0] ?? null;
        $suffix = $rest[1] ?? null;

        $action = match ($request->method()) {
            'POST' => 'store',
            'PUT', 'PATCH' => $suffix !== null ? $suffix : 'update',
            'DELETE' => 'destroy',
            default => null,
        };

        return ['uri' => $uri, 'action' => $action, 'target' => $target];
    }

    /**
     * 脱敏 + 限长后的请求体。
     *
     * @return array<string, mixed>|null
     */
    protected function sanitize(Request $request): ?array
    {
        $input = $request->except(['_token', '_method']);

        if ($input === []) {
            return null;
        }

        $clean = self::mask($input);

        $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return null;
        }

        if (mb_strlen($encoded) > self::MAX_PAYLOAD_CHARS) {
            return ['_truncated' => true, '_preview' => mb_substr($encoded, 0, self::MAX_PAYLOAD_CHARS)];
        }

        return $clean;
    }

    /**
     * 递归掩码敏感键。
     *
     * 公开静态是为了可测 —— 这是纯函数，「密码不落库」
     * 这条约束值得一个不依赖容器的单测。
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function mask(array $input): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $out[$key] = '******';

                continue;
            }

            $out[$key] = is_array($value) ? self::mask($value) : $value;
        }

        return $out;
    }

    public static function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 当前后台用户。
     *
     * ⚠️ 不能用 `$request->user()`。
     *
     * 它走的是 `config('auth.defaults.guard')`（通常是 web），
     * 而后台用的是 admin guard —— 结果 user_id / username
     * 全是 null，日志表里每一行都"不知道是谁干的"，
     * 审计价值直接归零（实测踩过：curl 登录后写入的日志
     * user_id 和 username 双双为 null）。
     *
     * Aimanong::user() 会按当前应用上下文取正确的 guard。
     */
    protected function currentUser(): ?Authenticatable
    {
        try {
            return Aimanong::user();
        } catch (Throwable) {
            return null;
        }
    }

    protected function userId(Request $request): ?int
    {
        $id = $this->currentUser()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    protected function username(Request $request): ?string
    {
        $user = $this->currentUser();

        if ($user === null) {
            return null;
        }

        /** @var mixed $raw */
        $raw = $user instanceof Model
            ? ($user->getAttribute('username') ?? $user->getAttribute('name'))
            : $user->getAuthIdentifier();

        return is_string($raw) ? mb_substr($raw, 0, 190) : null;
    }

    protected function ip(Request $request): ?string
    {
        return $request->ip();
    }
}
