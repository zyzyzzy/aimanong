<?php

declare(strict_types=1);

use Aimanong\Http\Controllers\Ai\IntrospectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI 自省接口
|--------------------------------------------------------------------------
|
| 让 AI Agent 直接询问"框架能做什么"，无需猜测 API。
|
|   GET  /__ai/capabilities.json   全部能力清单
|   GET  /__ai/schema/{uri}        某 Resource 的完整定义
|   GET  /__ai/openapi.json        全量 API 文档
|   GET  /__ai/context             Markdown 版 AI 上下文
|   GET  /__ai/examples/{pattern}  可复制代码片段
|   POST /__ai/verify              校验声明合法性
|
| 安全：默认仅 local/debug 开启；生产需 AIMANONG_AI_TOKEN。
|
*/

Route::get('capabilities.json', [IntrospectionController::class, 'capabilities'])
    ->name('capabilities');

Route::get('openapi.json', [IntrospectionController::class, 'openapi'])
    ->name('openapi');

Route::get('context', [IntrospectionController::class, 'context'])
    ->name('context');

Route::get('schema/{uri}', [IntrospectionController::class, 'schema'])
    ->name('schema');

Route::get('examples/{pattern}', [IntrospectionController::class, 'examples'])
    ->name('examples');

Route::post('verify', [IntrospectionController::class, 'verify'])
    ->name('verify');
