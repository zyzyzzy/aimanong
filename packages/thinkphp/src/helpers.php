<?php

declare(strict_types=1);

/*
 * ThinkPHP 侧的模板助手。
 *
 * ## 为什么需要
 *
 * `packages/thinkphp/resources/views/*.html` 是从 Laravel 那套 Blade
 * **生成**的（真源只有一份）。里面自然会调用 Laravel 的全局助手：
 *
 *     csrf_token()       TP 没有
 *     old()              TP 没有
 *     url() / config()   TP **有同名函数**（语义不同，但不会报未定义）
 *     app()              TP 有，但返回的是 think\App，没有 version()
 *
 * 两条路：改模板、或补助手。这里选**补助手** ——
 * 改模板意味着生成物与真源分叉，下次再生成又得改一遍。
 *
 * 全部用 `function_exists` 守卫：TP 自己有同名函数时不覆盖。
 */

if (! function_exists('csrf_token')) {
    /**
     * CSRF token。
     *
     * ThinkPHP 没有全局 csrf_token；这里返回会话中的 token，
     * 由本包的中间件负责校验（认证中间件尚未实现，见 ThinkRuntime 的注释）。
     */
    function csrf_token(): string
    {
        try {
            $token = session('_token');

            if (is_string($token) && $token !== '') {
                return $token;
            }

            $token = bin2hex(random_bytes(16));
            session('_token', $token);

            return $token;
        } catch (\Throwable) {
            return '';
        }
    }
}

if (! function_exists('old')) {
    /**
     * 表单回填值。
     *
     * Laravel 的 old() 从闪存会话取上一次提交；TP 侧这里只做
     * 「会话里有就用」的最小实现 —— 没有就返回默认值，不谎报。
     */
    function old(string $key, mixed $default = null): mixed
    {
        try {
            $value = session('_old.'.$key);

            return $value ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
