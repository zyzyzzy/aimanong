<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http;

use Aimanong\Aimanong;
use Aimanong\ThinkPHP\Auth\LoginThrottle;
use Aimanong\ThinkPHP\Auth\ThinkGuard;
use Aimanong\ThinkPHP\View\NullErrors;
use Aimanong\ThinkPHP\View\ThinkView;
use Aimanong\ThinkPHP\View\ViewErrors;
use think\Model;
use think\Request;
use think\Response;

/**
 * 登录 / 登出（ThinkPHP 版）。
 *
 * ## 与 Laravel 侧的差异（如实说明）
 *
 * | 能力 | Laravel | ThinkPHP（当前） |
 * |---|---|---|
 * | 登录 / 登出 | ✅ | ✅ |
 * | 记住我 | ✅ | ✅（remember_token 存哈希） |
 * | 登录限流 | ✅（5 次/分钟） | **未实现** |
 * | 多应用 guard | ✅ | 单应用 |
 *
 * 限流没做这件事**不掩饰**：它是防暴力破解的手段，
 * 少了它就不该对外暴露 —— 这一点写在 README 与 install 的输出里。
 */
final class AuthController
{
    public function showLoginForm(Request $request): Response
    {
        // 已登录还访问登录页 → 直接回后台，避免"登录了却停在登录页"的困惑
        if ($this->guard()->check()) {
            return redirect(Aimanong::url(''));
        }

        $html = (new ThinkView)->render('auth/login', [
            'errors' => new NullErrors,
            'old' => [],
            // 登录页模板里有 csrf 字段；TP 的 token 由 csrf_token() 助手提供
            'brand' => Aimanong::brand(),
        ]);

        return Response::create($html, 'html', 200);
    }

    public function login(Request $request): Response
    {
        $username = (string) $request->param('username', '');
        $password = (string) $request->param('password', '');
        $remember = (bool) $request->param('remember', false);

        $wantsJson = $request->isAjax() || str_contains($this->acceptHeader($request), 'application/json');

        if ($username === '' || $password === '') {
            return $this->fail($request, '请输入账号与密码。', 422);
        }

        /*
         * ⚠️ 账号名必须先规范化再进限流键 —— 与 Laravel 侧一致。
         *
         * 否则 `Admin` / `admin` / ` admin ` 是三个不同的计数桶，
         * 攻击者只要变换大小写就能绕过「每账号 5 次」的限制 ——
         * 限流看着在、实际形同虚设。
         */
        $throttleKey = mb_strtolower(trim($username));

        // 先看是否已被限流 —— 必须在 attempt 之前，
        // 否则已经超限的请求仍会去比对密码，限流形同虚设
        $lockedFor = LoginThrottle::lockedFor($request, $throttleKey);

        if ($lockedFor > 0) {
            return $this->fail(
                $request,
                sprintf('尝试过于频繁，请 %d 秒后重试', $lockedFor),
                429
            );
        }

        try {
            $ok = $this->guard()->attempt($username, $password, $remember);
        } catch (\RuntimeException $e) {
            // 账号被禁用 —— 与「密码错误」区分开，否则管理员查不出原因
            LoginThrottle::hit($request, $throttleKey);

            return $this->fail($request, $e->getMessage(), 403);
        }

        // 失败才计数；成功则清零（否则打错几次再登对会被后续计数挡住）
        LoginThrottle::hit($request, $throttleKey);

        if (! $ok) {
            return $this->fail($request, '账号或密码不正确。', 401);
        }

        LoginThrottle::clear($request, $throttleKey);
        $this->touchLogin($request);

        if ($wantsJson) {
            return Response::create([
                'message' => '登录成功',
                'redirect' => Aimanong::url(''),
            ], 'json', 200);
        }

        return redirect(Aimanong::url(''));
    }

    public function logout(Request $request): Response
    {
        $this->guard()->logout();

        if ($request->isAjax() || str_contains($this->acceptHeader($request), 'application/json')) {
            return Response::create(['message' => '已退出登录'], 'json', 200);
        }

        return redirect(Aimanong::url('auth/login'));
    }

    /**
     * 记录登录时间 / IP。
     *
     * ⚠️ 记不上**不应**导致登录失败 —— 用户在字段缺失的老库上也能登进来。
     */

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

    protected function touchLogin(Request $request): void
    {
        try {
            $user = $this->guard()->user();

            /*
             * ⚠️ 必须显式收窄成 `think\Model`。
             *
             * 多应用下登录用户可能是**任意**实现了 `Permissible` 的模型，
             * 所以守卫的返回类型是 `object`（框架契约定死的）。
             * 这里要用模型自身的 `getData()` / `save()`，就得自己判断 ——
             * 不能假设它一定是某个具体类。
             */
            if (! $user instanceof Model) {
                return;
            }

            $payload = [];

            if (array_key_exists('last_login_at', $user->getData())) {
                $payload['last_login_at'] = date('Y-m-d H:i:s');
            }

            if (array_key_exists('last_login_ip', $user->getData())) {
                $payload['last_login_ip'] = $request->ip();
            }

            if ($payload !== []) {
                $user->save($payload);
            }
        } catch (\Throwable) {
            // 刻意吞掉：审计信息不该block登录
        }
    }

    protected function fail(Request $request, string $message, int $status): Response
    {
        $wantsJson = $request->isAjax() || str_contains($this->acceptHeader($request), 'application/json');

        if ($wantsJson) {
            return Response::create(['message' => $message], 'json', $status);
        }

        // 非 AJAX：把错误带回登录页。模板读 $errors->first()，
        // 所以这里给一个能报错的实现（不是 NullErrors）。
        $html = (new ThinkView)->render('auth/login', [
            'errors' => new ViewErrors($message),
            'old' => ['username' => (string) $request->param('username', '')],
            'brand' => Aimanong::brand(),
        ]);

        return Response::create($html, 'html', $status);
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
