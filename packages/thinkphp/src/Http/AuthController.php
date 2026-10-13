<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http;

use Aimanong\ThinkPHP\Auth\ThinkGuard;
use Aimanong\ThinkPHP\View\NullErrors;
use Aimanong\ThinkPHP\View\ViewErrors;
use Aimanong\ThinkPHP\View\ThinkView;
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
        if ((new ThinkGuard)->check()) {
            return redirect(\Aimanong\Aimanong::url(''));
        }

        $html = (new ThinkView)->render('auth/login', [
            'errors' => new NullErrors,
            'old' => [],
            // 登录页模板里有 csrf 字段；TP 的 token 由 csrf_token() 助手提供
            'brand' => \Aimanong\Aimanong::brand(),
        ]);

        return Response::create($html, 'html', 200);
    }

    public function login(Request $request): Response
    {
        $username = (string) $request->param('username', '');
        $password = (string) $request->param('password', '');
        $remember = (bool) $request->param('remember', false);

        $wantsJson = $request->isAjax() || str_contains((string) $request->header('accept', ''), 'application/json');

        if ($username === '' || $password === '') {
            return $this->fail($request, '请输入账号与密码。', 422);
        }

        try {
            $ok = (new ThinkGuard)->attempt($username, $password, $remember);
        } catch (\RuntimeException $e) {
            // 账号被禁用 —— 与「密码错误」区分开，否则管理员查不出原因
            return $this->fail($request, $e->getMessage(), 403);
        }

        if (! $ok) {
            return $this->fail($request, '账号或密码不正确。', 401);
        }

        $this->touchLogin($request);

        if ($wantsJson) {
            return Response::create([
                'message' => '登录成功',
                'redirect' => \Aimanong\Aimanong::url(''),
            ], 'json', 200);
        }

        return redirect(\Aimanong\Aimanong::url(''));
    }

    public function logout(Request $request): Response
    {
        (new ThinkGuard)->logout();

        if ($request->isAjax() || str_contains((string) $request->header('accept', ''), 'application/json')) {
            return Response::create(['message' => '已退出登录'], 'json', 200);
        }

        return redirect(\Aimanong\Aimanong::url('auth/login'));
    }

    /**
     * 记录登录时间 / IP。
     *
     * ⚠️ 记不上**不应**导致登录失败 —— 用户在字段缺失的老库上也能登进来。
     */
    protected function touchLogin(Request $request): void
    {
        try {
            $user = (new ThinkGuard)->user();

            if ($user === null) {
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
        $wantsJson = $request->isAjax() || str_contains((string) $request->header('accept', ''), 'application/json');

        if ($wantsJson) {
            return Response::create(['message' => $message], 'json', $status);
        }

        // 非 AJAX：把错误带回登录页。模板读 $errors->first()，
        // 所以这里给一个能报错的实现（不是 NullErrors）。
        $html = (new ThinkView)->render('auth/login', [
            'errors' => new ViewErrors($message),
            'old' => ['username' => (string) $request->param('username', '')],
            'brand' => \Aimanong\Aimanong::brand(),
        ]);

        return Response::create($html, 'html', $status);
    }
}
