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

use Aimanong\ThinkPHP\Http\HomeController;
use think\facade\Route;

// 首页：/admin
Route::get('/', [HomeController::class, 'index']);
