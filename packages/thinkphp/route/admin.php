<?php

declare(strict_types=1);

/*
 * Aimanong 后台路由（ThinkPHP 版）。
 *
 * 与 Laravel 侧的 `routes/admin.php` 结构对应，但**不做逐条照搬** ——
 * 目前只落地「控制台首页」这一条，用来证明页面链路（路由 → 控制器 →
 * 模板 → 响应）在 ThinkPHP 上真的通。其余路由（数据 API、各页面、
 * 认证）随对应能力一起补。
 *
 * 只做一条而不是先把路由表铺开，是因为**能返回 200 的一条**比
 * 铺开但打不开的一片更有信息量：缺什么会立刻暴露。
 */

use Aimanong\ThinkPHP\Http\AuthController;
use Aimanong\ThinkPHP\Http\HomeController;
use Aimanong\ThinkPHP\Http\Middleware\Authenticate;
use Aimanong\ThinkPHP\Http\ResourceController;
use think\facade\Route;

/*
| 登录 / 登出 —— 必须挂在鉴权中间件**之外**，
| 否则跳登录页时又要求登录，形成无限重定向。
*/
Route::get('auth/login', [AuthController::class, 'showLoginForm']);
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/logout', [AuthController::class, 'logout']);

/*
| 其余路由统一要求登录。
*/
Route::group(function (): void {
    // 首页：/admin
    Route::get('/', [HomeController::class, 'index']);

    /*
    | 数据 API —— JSON，供前端 Vue 与 AI Agent 调用
    |
    | 路径：/admin/api/{uri}
    |
    | 只登记**已实现**的动作。未移植的四个（batch / export / tree / move）
    | 不在这里挂着 —— 它们会被 TP 当成默认控制器路由，报「控制器不存在」，
    | 那是个让人摸不着头脑的错误。真要调时由 unimplemented() 明确回 501。
    */
    /*
     * ⚠️ `:uri` 必须显式允许**连字符**。
     *
     * 实测踩到：TP 路由变量的默认匹配不含 `-`，于是 `/api/readonly-customers`
     * 被截成 `/api/readonly`，报「Resource [readonly] 未注册」——
     * 一个看起来像「资源没注册」的错，实际是路由把名字切断了。
     *
     * 影响面不小：带连字符的资源名很常见（`shop-products`、`order-items`…）。
     */
    Route::group('api', function (): void {
        // ⚠️ 顺序：带 :id 的**必须**排在前面。
        //    实测踩到：写成 ':uri' 在前时，TP 会用 ':uri' 匹配掉
        //    `/api/customers/999999` 整个路径（多余的段被吞），
        //    于是详情请求走进了列表接口 —— 返回 200 + 列表数据，
        //    调用方完全看不出错。
        $uri = '[\w\-]+';

        Route::get(':uri/:id', [ResourceController::class, 'show'])->pattern(['uri' => $uri]);
        Route::get(':uri', [ResourceController::class, 'index'])->pattern(['uri' => $uri]);
        Route::post(':uri', [ResourceController::class, 'store'])->pattern(['uri' => $uri]);
        Route::put(':uri/:id', [ResourceController::class, 'update'])->pattern(['uri' => $uri]);
        Route::delete(':uri/:id', [ResourceController::class, 'destroy'])->pattern(['uri' => $uri]);
    });
})->middleware(Authenticate::class);
