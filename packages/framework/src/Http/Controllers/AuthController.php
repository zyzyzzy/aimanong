<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Aimanong\Foundation\Audit\AuditRecorder;
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
        if (! Aimanong::guard()->attempt($credentials)) {
            $audit->recordLogin($credentials['username'], false, null, '用户名或密码错误', $request);

            throw ValidationException::withMessages([
                'username' => ['用户名或密码错误'],
            ]);
        }

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

    protected function currentUserId(): ?int
    {
        $id = Aimanong::user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        Aimanong::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => '已退出登录']);
        }

        return redirect(Aimanong::url('auth/login'));
    }
}
