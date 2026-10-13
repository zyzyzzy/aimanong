<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Auth;

use think\facade\Cache;
use think\facade\Config;
use think\Request;

/**
 * 登录限流 —— 防暴力破解。
 *
 * 与 Laravel 侧**同两个维度、同一套语义**：
 *
 *   per_ip       每 IP 每窗口的次数（默认 20）
 *   per_account  每 IP 对每账号每窗口的次数（默认 5）
 *
 * 两个维度叠加，至少保证「同一 IP 对同一账号」始终受限。
 *
 * ## 为什么没有直接用 TP 的 `throttle` 中间件
 *
 * TP 的 `think\middleware\Throttle` 是按**路由**限流、且只按 IP。
 * 登录需要的是「按账号」维度的限制 —— 否则攻击者换 IP 就能继续猜同一个账号。
 *
 * ## 存储
 *
 * 用 Cache（默认 file 驱动），不引额外依赖。计数与过期时间一起存，
 * 这样能算出「还要等几秒」—— 只回一句「太频繁」用户不知道等多久。
 */
final class LoginThrottle
{
    public const CACHE_PREFIX = 'aimanong:login:';

    public static function enabled(): bool
    {
        return (bool) self::cfg('aimanong.auth.throttle.enable', true);
    }

    /**
     * 校验是否已被限流（**在尝试之前**调用）。
     *
     * @return int 0 = 未限流；>0 = 需要等待的秒数
     */
    public static function lockedFor(Request $request, string $username): int
    {
        if (! self::enabled()) {
            return 0;
        }

        foreach (self::limiters($request, $username) as $key => $max) {
            $entry = self::read($key);

            if ($entry['n'] >= $max) {
                return max(1, $entry['until'] - time());
            }
        }

        return 0;
    }

    /**
     * 记一次尝试。**无论成功失败都要调**（失败计数、成功清零）。
     */
    public static function hit(Request $request, string $username): void
    {
        if (! self::enabled()) {
            return;
        }

        $window = self::window();

        foreach (self::limiters($request, $username) as $key => $max) {
            if ($max <= 0) {
                continue;
            }

            $entry = self::read($key);

            /*
             * 首次命中才设 TTL —— 后续递增**不刷新**过期时间。
             * 否则持续攻击会把窗口无限延长，正常用户永远等不到解锁。
             */
            if ($entry['n'] === 0) {
                Cache::set($key, ['n' => 1, 'until' => time() + $window], $window);

                continue;
            }

            $entry['n']++;
            $remain = max(1, $entry['until'] - time());
            Cache::set($key, $entry, $remain);
        }
    }

    /**
     * 登录成功后清零 —— 否则用户打错几次再登对，仍会被后续的计数挡住。
     */
    public static function clear(Request $request, string $username): void
    {
        if (! self::enabled()) {
            return;
        }

        foreach (array_keys(self::limiters($request, $username)) as $key) {
            Cache::delete($key);
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

        $perIp = (int) self::cfg('aimanong.auth.throttle.per_ip', 20);

        if ($perIp > 0) {
            $out[self::CACHE_PREFIX.'ip:'.md5($ip)] = $perIp;
        }

        $perAccount = (int) self::cfg('aimanong.auth.throttle.per_account', 5);

        if ($perAccount > 0 && $username !== '') {
            $out[self::CACHE_PREFIX.'acct:'.md5($ip.'|'.$username)] = $perAccount;
        }

        return $out;
    }

    /**
     * @return array{n: int, until: int}
     */
    protected static function read(string $key): array
    {
        try {
            /** @var mixed $raw */
            $raw = Cache::get($key);
        } catch (\Throwable) {
            return ['n' => 0, 'until' => 0];
        }

        if (! is_array($raw) || ! isset($raw['n'], $raw['until'])) {
            return ['n' => 0, 'until' => 0];
        }

        return ['n' => (int) $raw['n'], 'until' => (int) $raw['until']];
    }

    protected static function window(): int
    {
        return max(1, (int) self::cfg('aimanong.auth.throttle.window', 60));
    }

    protected static function cfg(string $key, mixed $default): mixed
    {
        try {
            return Config::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
