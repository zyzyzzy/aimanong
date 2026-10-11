<?php

declare(strict_types=1);

use Aimanong\Foundation\Upload\UploadController;
use Aimanong\Http\Controllers\AuthController;
use Aimanong\Http\Controllers\HomeController;
use Aimanong\Http\Controllers\ResourceController;
use Aimanong\Http\Controllers\UiController;
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
| 框架内置资源（LOGO 等）—— 免 publish 直接可用
*/
Route::get('assets/{path}', function (string $path) {
    $full = realpath(__DIR__.'/../resources/assets/'.$path);
    $base = realpath(__DIR__.'/../resources/assets');

    // 防目录穿越
    if ($full === false || $base === false || ! str_starts_with($full, $base)) {
        abort(404);
    }

    /*
     * 必须显式指定 Content-Type。
     *
     * response()->file() 在这台环境上把 .css 猜成了 text/html ——
     * 浏览器在严格 MIME 模式下会拒绝解析（stylesheets 的 cssRules 为 0，
     * 表现为「CSS 加载了但样式完全不生效」，极难排查）。
     */
    $mime = match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        default => 'application/octet-stream',
    };

    return response()->file($full, ['Content-Type' => $mime]);
})->where('path', '.*')->name('asset');

/*
| 已上传文件的读取。
|
| 路径用 `file/` 而不是 `uploads/`：存储目录本身通常就叫 uploads，
| 用 uploads 做路由前缀会拼出 /admin/uploads/uploads/... 这种滑稽路径。
|
| 由 Uploader::serve() 的 auto 模式决定是否走这里（local 磁盘默认走）。
| 放在 admin 组内 = 需要登录 —— 后台上传的合同附件本来就不该匿名可下。
| 需要公开直读就用 storage:link + serve=url。
*/
Route::get('file/{path}', [UploadController::class, 'show'])
    ->where('path', '.*')
    ->name('upload.show');

/*
| 数据 API
*/
Route::prefix('api')->name('api.')->group(function (): void {
    /*
     * 界面偏好（主题/密度/圆角）。
     *
     * 这里曾经写成 'api/ui/preferences'，而外层已经有 Route::prefix('api')，
     * 实际路径变成 /admin/api/api/ui/preferences —— 与前端
     * （Aimanong::url('api/ui/preferences')）对不上：
     * 保存返回 405、读取返回「Resource [ui] 未注册」，
     * 主题面板的跨设备同步一直是坏的。
     *
     * 必须声明在 {uri} 之前 —— 否则 'ui/preferences' 会被
     * 当成 uri=ui、id=preferences。
     */
    Route::get('ui/preferences', [UiController::class, 'show'])->name('ui.preferences');
    Route::post('ui/preferences', [UiController::class, 'store'])->name('ui.preferences.save');

    /*
     * 文件上传。
     *
     * 必须在 {uri} 之前 —— 否则 'upload' 会被当成 uri。
     */
    Route::post('upload', [UploadController::class, 'store'])->name('upload');

    Route::get('{uri}', [ResourceController::class, 'index'])->name('index');
    Route::post('{uri}', [ResourceController::class, 'store'])->name('store');

    // 静态子路径必须声明在 {uri}/{id} 之前，否则会被当作 id
    Route::get('{uri}/export', [ResourceController::class, 'export'])->name('export');
    Route::get('{uri}/tree', [ResourceController::class, 'tree'])->name('tree');
    Route::put('{uri}/{id}/move', [ResourceController::class, 'move'])->name('move');

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
