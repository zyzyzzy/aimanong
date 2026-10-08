<?php

declare(strict_types=1);

use Aimanong\Http\Controllers\AuthController;
use Aimanong\Http\Controllers\HomeController;
use Aimanong\Http\Controllers\ResourceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI 码农 后台路由
|--------------------------------------------------------------------------
|
| 由 AimanongServiceProvider 以 Route::middleware('admin') 组载入，
| 用户无需手动注册路由。
|
| 两类路由：
|   1. /api/{uri}/*   数据 API —— JSON，供前端 Vue 与 AI Agent 调用
|   2. /{uri}         页面路由 —— 返回 Vue SPA 外壳
|
*/

Route::get('auth/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('auth/login', [AuthController::class, 'login'])->name('login.post');
Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/', [HomeController::class, 'index'])->name('index');

/*
| 数据 API
*/
Route::prefix('api')->name('api.')->group(function (): void {
    Route::get('{uri}', [ResourceController::class, 'index'])->name('index');
    Route::post('{uri}', [ResourceController::class, 'store'])->name('store');
    Route::get('{uri}/{id}', [ResourceController::class, 'show'])->name('show');
    Route::put('{uri}/{id}', [ResourceController::class, 'update'])->name('update');
    Route::delete('{uri}/{id}', [ResourceController::class, 'destroy'])->name('destroy');
});

/*
| 页面路由：返回 Vue SPA 外壳，实际渲染由前端按 Schema 完成。
| 置于最后，避免拦截上面的具体路由。
*/
Route::get('{uri}', [HomeController::class, 'resource'])
    ->where('uri', '[a-z0-9\-]+')
    ->name('resource');
