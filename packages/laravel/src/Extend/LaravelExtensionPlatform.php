<?php

declare(strict_types=1);

namespace Aimanong\Extend;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * 扩展平台 —— Laravel 实现。
 *
 * 这 4 个方法原来直接长在 `Extension` / `ExtensionManager` 里，
 * 于是那两个类被钉死在 Laravel 上。抽出来之后：
 * 基类在 core，两个驱动各提供一个平台实现，
 * **用户写的扩展类只写一次**，装到哪个框架都能跑。
 */
final class LaravelExtensionPlatform implements ExtensionPlatform
{
    public function routes(Extension $extension, \Closure $callback, string $prefix): void
    {
        Route::middleware('admin')
            ->prefix($prefix.'/extensions/'.$extension->name())
            ->name('aimanong.ext.'.$extension->name().'.')
            ->group($callback);
    }

    public function views(string $namespace, string $path): void
    {
        app('view')->addNamespace($namespace, $path);
    }

    public function translations(string $namespace, string $path): void
    {
        app('translator')->addNamespace($namespace, $path);
    }

    public function logFailure(string $name, string $phase, \Throwable $e): void
    {
        try {
            Log::warning(
                "[Aimanong] 扩展 {$name} {$phase}() 失败",
                ['exception' => $e]
            );
        } catch (\Throwable) {
            // 日志不可用时静默 —— 失败原因已记录在 ExtensionManager::$failed
        }
    }
}
