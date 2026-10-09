<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
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
     */
    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        /** @var array{username: string, password: string} $credentials */
        if (! Aimanong::guard()->attempt($credentials)) {
            throw ValidationException::withMessages([
                'username' => ['用户名或密码错误'],
            ]);
        }

        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json(['message' => '登录成功', 'redirect' => Aimanong::url('/')]);
        }

        return redirect()->intended(Aimanong::url('/'));
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
