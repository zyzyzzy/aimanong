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
use Aimanong\ThinkPHP\Http\ResourceController;
use think\facade\Route;

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
Route::group('api', function (): void {
    // ⚠️ 顺序：带 :id 的**必须**排在前面。
    //    实测踩到：写成 ':uri' 在前时，TP 会用 ':uri' 匹配掉
    //    `/api/customers/999999` 整个路径（多余的段被吞），
    //    于是详情请求走进了列表接口 —— 返回 200 + 列表数据，
    //    调用方完全看不出错。
    Route::get(':uri/:id', [ResourceController::class, 'show']);
    Route::get(':uri', [ResourceController::class, 'index']);
    Route::post(':uri', [ResourceController::class, 'store']);
    Route::put(':uri/:id', [ResourceController::class, 'update']);
    Route::delete(':uri/:id', [ResourceController::class, 'destroy']);
});
