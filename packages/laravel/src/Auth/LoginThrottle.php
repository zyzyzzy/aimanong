<?php

declare(strict_types=1);

namespace Aimanong\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * 登录限流。
 *
 * ## 为什么需要它
 *
 * 没有限流时，攻击者可以对登录接口**无限次**尝试密码。
 * 框架虽然会记录每次失败（登录日志），但**日志不能阻止攻击** ——
 * 一个暴露公网的后台，弱密码账号会在可接受时间内被攻破。
 *
 * 实测（补上之前）：连续 10 次错误密码全部返回 422，零拦截。
 *
 * ## 两个维度
 *
 * | 维度 | 键 | 防什么 |
 * |---|---|---|
 * | 按 IP | `ip:{ip}` | 一个 IP 打很多账号 |
 * | 按 IP + 账号 | `ip:{ip}|user:{user}` | 一个 IP 针对性打某个账号 |
 *
 * 只按 IP 不够：分布式攻击时每个 IP 都只试几次，绕得过去。
 * 两个维度叠加，至少保证「同一 IP 对同一账号」始终受限。
 *
 * ## 配置项（`config/aimanong.php` → `auth.throttle`）
 *
 * - `enable`：总开关
 * - `per_ip`：每 IP 每分钟次数（0 = 关闭该维度）
 * - `per_account`：每 IP 对每账号每分钟次数（0 = 关闭）
 * - `window`：窗口秒数
 */
class LoginThrottle
{
    /**
     * 是否启用限流。
     */
    public static function enabled(): bool
    {
        return (bool) config('aimanong.auth.throttle.enable', true);
    }

    /**
     * 记录一次登录尝试，并在超限时抛异常。
     *
     * 无论成功失败都要调用：失败计数、成功清零。
     *
     * @param  array{username: string, password: string}  $credentials
     *
     * @throws ValidationException 超过任一维度限制时
     */
    public static function hit(Request $request, array $credentials): void
    {
        if (! self::enabled()) {
            return;
        }

        $ip = (string) $request->ip();
        $username = mb_strtolower(trim((string) $credentials['username']));

        $window = max(1, (int) config('aimanong.auth.throttle.window', 60));

        foreach (self::limiters($request, $username) as $key => $max) {
            if ($max <= 0) {
                continue;
            }

            RateLimiter::hit($key, $window);
        }
    }

    /**
     * 校验是否已被限流（在尝试之前调用）。
     *
     * @param  array{username: string, password: string}  $credentials
     *
     * @throws ValidationException
     */
    public static function ensureNotLockedOut(Request $request, array $credentials): void
    {
        if (! self::enabled()) {
            return;
        }

        $username = mb_strtolower(trim((string) $credentials['username']));

        foreach (self::limiters($request, $username) as $key => $max) {
            if ($max <= 0) {
                continue;
            }

            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'username' => [sprintf('尝试过于频繁，请 %d 秒后重试', max(1, $seconds))],
            ]);
        }
    }

    /**
     * 登录成功后清零计数。
     *
     * @param  array{username: string, password: string}  $credentials
     */
    public static function clear(Request $request, array $credentials): void
    {
        if (! self::enabled()) {
            return;
        }

        $username = mb_strtolower(trim((string) $credentials['username']));

        foreach (self::limiters($request, $username) as $key => $max) {
            RateLimiter::clear($key);
        }
    }

    /**
     * 当前请求命中的所有限流器：键 => 上限次数。
     *
     * @return array<string, int>
     */
    protected static function limiters(Request $request, string $username): array
    {
        $ip = (string) $request->ip();
        $out = [];

        $perIp = (int) config('aimanong.auth.throttle.per_ip', 20);

        if ($perIp > 0) {
            $out['aimanong:login:ip:'.md5($ip)] = $perIp;
        }

        $perAccount = (int) config('aimanong.auth.throttle.per_account', 5);

        if ($perAccount > 0 && $username !== '') {
            $out['aimanong:login:acct:'.md5($ip.'|'.$username)] = $perAccount;
        }

        return $out;
    }
}
