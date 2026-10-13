<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Aimanong\Auth\AdminGuard;
use Aimanong\Auth\LoginThrottle;
use Aimanong\Foundation\Audit\AuditRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLoginForm(Request $request): View|RedirectResponse
    {
        if (Aimanong::user() !== null) {
            return redirect(Aimanong::url('/'));
        }

        return view('aimanong::auth.login');
    }

    /**
     * 登录。签名唯一，不做重载。
     *
     * 登录日志在这里记，而不是监听 Laravel 的 Login/Failed 事件 ——
     * 自定义 guard 不保证会派发这些事件，而本控制器是框架**唯一**
     * 的登录入口，写在这里不可能漏记，也不会重复记。
     */
    public function login(Request $request): JsonResponse|RedirectResponse
    {
        /** @var AuditRecorder $audit */
        $audit = app(AuditRecorder::class);

        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        /** @var array{username: string, password: string} $credentials */

        /*
         * 限流：先检查是否已被锁，再尝试登录。
         *
         * 顺序很重要 —— 若先 attempt 再检查，被限流期间
         * 每一次请求仍然会真的去查一次数据库 + 校验密码。
         * 那等于「限流了但没真的省力」，爆破仍在进行。
         */
        LoginThrottle::ensureNotLockedOut($request, $credentials);

        /** @var AdminGuard $guard */
        $guard = Aimanong::guard();

        if (! $guard->attempt($credentials)) {
            // 失败：计数（下次就会被拦）
            LoginThrottle::hit($request, $credentials);

            $audit->recordLogin($credentials['username'], false, null, '用户名或密码错误', $request);

            throw ValidationException::withMessages([
                'username' => ['用户名或密码错误'],
            ]);
        }

        // 成功：清零（否则用户手滑几次后正常登录也会被算进窗口）
        LoginThrottle::clear($request, $credentials);

        // 记录「最后登录时间/IP」——用户管理列表要用
        $this->markLoggedIn($request);

        $audit->recordLogin(
            $credentials['username'],
            true,
            $this->currentUserId(),
            null,
            $request
        );

        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json(['message' => '登录成功', 'redirect' => Aimanong::url('/')]);
        }

        return redirect()->intended(Aimanong::url('/'));
    }

    /**
     * 更新当前用户的最后登录信息。
     *
     * 用 method_exists 而不是直接写属性：用户的自定义管理员模型
     * 可能没有这个方法（老项目没跑迁移），那就跳过 ——
     * 记不上登录时间不该导致登录失败。
     */
    protected function markLoggedIn(Request $request): void
    {
        /** @var Authenticatable|null $user */
        $user = Aimanong::user();

        if ($user !== null && method_exists($user, 'markLoggedIn')) {
            $user->markLoggedIn($request->ip());
        }
    }

    protected function currentUserId(): ?int
    {
        /** @var Authenticatable|null $current */
        $current = Aimanong::user();
        $id = $current?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        /** @var AdminGuard $guard */
        $guard = Aimanong::guard();
        $guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => '已退出登录']);
        }

        return redirect(Aimanong::url('auth/login'));
    }
}
