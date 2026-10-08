<?php

declare(strict_types=1);

use Aimanong\Http\Controllers\AuthController;
use Aimanong\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI 码农 后台路由
|--------------------------------------------------------------------------
|
| 由 AimanongServiceProvider 以 Route::middleware('admin') 组载入，
| 用户无需手动注册路由。
|
*/

Route::get('auth/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('auth/login', [AuthController::class, 'login'])->name('login.post');
Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/', [HomeController::class, 'index'])->name('index');
