<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Extend\Extension;
use Aimanong\Extend\ExtensionPlatform;
use Aimanong\ThinkPHP\Http\Middleware\Authenticate;
use think\facade\Lang;
use think\facade\Log;
use think\facade\Route;

/**
 * 扩展平台 —— ThinkPHP 实现。
 *
 * 与 Laravel 侧**同一套语义**：
 *   · 路由自动挂 `/<后台前缀>/extensions/<扩展名>`，并带鉴权中间件
 *   · 视图/翻译各自注册一个命名空间
 *   · 失败只记日志，不把「一个扩展坏了」升级成「整个后台起不来」
 *
 * ## 鉴权中间件
 *
 * Laravel 侧写的是 `->middleware('admin')`（别名在驱动里注册）。
 * TP 没有中间件别名机制，这里直接用 `Authenticate` 类 ——
 * 与 `route/admin.php` 里 `Route::group(...)->middleware(Authenticate::class)`
 * 是同一个类，语义一致。
 */
final class ThinkExtensionPlatform implements ExtensionPlatform
{
    public function routes(Extension $extension, \Closure $callback, string $prefix): void
    {
        Route::group(
            $prefix.'/extensions/'.$extension->name(),
            $callback
        )->middleware(Authenticate::class);
    }

    /**
     * 已注册的视图命名空间。
     *
     * @var array<string, string>
     */
    private static array $viewNamespaces = [];

    /**
     * 注册视图命名空间。
     *
     * ## ⚠️ 这里必须说清楚：TP 上它只完成「登记」，没有渲染方消费
     *
     * `think-template` 的 `view_path` 是**单个字符串**
     * （`Template.php:1231` 直接做 `view_path . $template . $suffix`），
     * TP 侧**没有** Laravel 的 `view()->addNamespace()` 那种机制。
     *
     * 所以本方法把命名空间记在 `self::$viewNamespaces` 里，
     * 供**自己渲染插件视图的扩展**用（`ThinkExtensionPlatform::viewPathOf()`）。
     *
     * 而 `Extension::fieldView()` / `fieldScript()` —— 也就是
     * 「插件自带字段 UI」那条路 —— TP 的渲染器（`resource.html`）**还不读**
     * （见该模板里的注释）。所以装了 region 的 TP 项目：
     *   · 扩展会正常注册、`region` 字段类型会被注入 ✅
     *   · 但字段的**自定义 UI** 不渲染 ⚠️
     *
     * 不假装它可用 —— 「声明了但没人消费」正是本项目反复踩的坑。
     */
    public function views(string $namespace, string $path): void
    {
        self::$viewNamespaces[$namespace] = rtrim($path, '/');
    }

    /**
     * 取某个命名空间下的模板绝对路径（未注册时返回 null）。
     */
    public static function viewPathOf(string $namespace, string $template): ?string
    {
        $base = self::$viewNamespaces[$namespace] ?? null;

        if ($base === null) {
            return null;
        }

        foreach (['html', 'php'] as $suffix) {
            $file = $base.'/'.$template.'.'.$suffix;

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public static function viewNamespaces(): array
    {
        return self::$viewNamespaces;
    }

    public function translations(string $namespace, string $path): void
    {
        /*
         * ⚠️ TP 没有 `translator->addNamespace()` 那种「注册命名空间」的入口。
         *
         * `Lang::load()` 是按文件加载的，不是按目录。所以这里逐个
         * `*.php` 文件加载并打上 `命名空间::键` 的前缀 ——
         * 效果与 Laravel 的 `addNamespace` 等价（`lang('ns::key')` 可用）。
         */
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) glob($path.'/*.php') as $file) {
            if (! is_string($file)) {
                continue;
            }

            $group = $namespace.'::'.basename($file, '.php');

            try {
                /** @var mixed $data */
                $data = include $file;

                if (is_array($data)) {
                    Lang::load($data, $group);
                }
            } catch (\Throwable) {
                // 单个语言文件坏了不该拖垮启动
            }
        }
    }

    public function logFailure(string $name, string $phase, \Throwable $e): void
    {
        try {
            Log::warning("[Aimanong] 扩展 {$name} {$phase}() 失败: ".$e->getMessage());
        } catch (\Throwable) {
            // 日志不可用时静默 —— 失败原因已记录在 ExtensionManager::$failed
        }
    }
}
