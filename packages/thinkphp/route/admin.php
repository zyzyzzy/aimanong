<?php

declare(strict_types=1);

/*
 * Aimanong 后台路由（ThinkPHP 版）。
 *
 * 与 Laravel 侧的 `routes/admin.php` 结构对应。
 *
 * 已落地：控制台首页 · 资源页面（HTML）· 数据 API（JSON）· 认证。
 * 未移植：batch / export / tree / move（调时由 unimplemented() 回 501）。
 */

use Aimanong\ThinkPHP\Http\AssetController;
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
| 静态资源 —— 必须在鉴权**之外**。
|
| 登录页本身也要用 LOGO 与设计系统 CSS；挂在鉴权里会让登录页无样式。
| （Laravel 侧的 assets 路由同样在鉴权组之外。）
|
| ⚠️ 没有这条路由时，全部 CSS/JS 请求 404 —— 后台**完全没有样式**，
| 而页面本身返回 200，不报任何错。实测踩到。
*/
Route::get('assets/:path', [AssetController::class, 'show'])
    ->pattern(['path' => '.*']);

/*
| 其余路由统一要求登录。
*/
Route::group(function (): void {
    // 首页：/admin
    //
    // 注意这里只登记 `/`（规则为 `/admin`）。`Aimanong::url()` 空参也返回
    // `/admin`（不带尾斜杠）—— TP 的显式规则不做尾斜杠归一化，
    // 两边必须约定同一个形态，否则「点 LOGO 回首页」会 404。
    Route::get('/', [HomeController::class, 'index']);

    /*
    | 数据 API —— JSON，供前端 Vue 与 AI Agent 调用
    |
    | 路径：/admin/api/{uri}
    |
    | 只登记**已实现**的动作。未移植的三个（batch / export / move）
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

        // ⚠️ `:uri/tree` 必须排在 `:uri/:id` **之前** ——
        //    否则 TP 会把 `tree` 当成 id，走进详情接口报「不存在 id=tree 的记录」。
        Route::get(':uri/tree', [ResourceController::class, 'tree'])->pattern(['uri' => $uri]);
        Route::get(':uri/:id', [ResourceController::class, 'show'])->pattern(['uri' => $uri]);
        Route::get(':uri', [ResourceController::class, 'index'])->pattern(['uri' => $uri]);
        Route::post(':uri', [ResourceController::class, 'store'])->pattern(['uri' => $uri]);
        Route::put(':uri/:id', [ResourceController::class, 'update'])->pattern(['uri' => $uri]);
        Route::delete(':uri/:id', [ResourceController::class, 'destroy'])->pattern(['uri' => $uri]);
    });

    /*
    | 资源页面（HTML）—— 兜底路由
    |
    | ⚠️ **必须放在所有具体路由之后**。
    | TP 路由是先注册先匹配；若放在 `/` 或 `api/*` 之前，
    | 它会吞掉那些请求（把 `api` 当成 uri 参数）。
    |
    | 与 Laravel 侧一致：`Route::get('{uri}', [HomeController::class, 'resource'])`
    | 也是放在路由表最末尾。
    */
    $uriPattern = '[\w\-]+';
    Route::get(':uri', [HomeController::class, 'resource'])
        ->pattern(['uri' => $uriPattern]);
})->middleware(Authenticate::class);
