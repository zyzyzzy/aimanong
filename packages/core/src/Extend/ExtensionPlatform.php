<?php

declare(strict_types=1);

namespace Aimanong\Extend;

/**
 * 扩展机制里「只有驱动才知道」的几件事。
 *
 * ## 为什么需要它
 *
 * `Extension` / `ExtensionManager` 共 474 行，其中 **99% 是框架无关的**：
 * 依赖解析、注册时序、失败隔离、字段类型注入、自省输出……
 * 真正碰框架的只有 4 个点：
 *
 * | 点 | Laravel | ThinkPHP |
 * |---|---|---|
 * | `routes()` | `Route::middleware('admin')->prefix(...)` | `Route::group(...)` |
 * | `views()` | `app('view')->addNamespace()` | `View::addNamespace()` |
 * | `translations()` | `app('translator')->addNamespace()` | `Lang::load()` |
 * | 失败日志 | `Log::warning()` | `Log::warning()`（TP 同名但不同类） |
 *
 * ## 为什么不能各写一份
 *
 * **用户写的扩展类只有一个 FQN**：
 *
 *     class MyExtension extends \Aimanong\Extend\Extension
 *
 * 两个驱动包不可能都定义它。所以基类**必须只存在于 core**，
 * 而这 4 个点通过本接口由驱动注入。
 *
 * 这也是「同一份声明多框架行为一致」在扩展机制上的落地：
 * 用户写一次扩展，装到 Laravel 或 TP 上都能跑。
 *
 * ## 未注册平台时
 *
 * `current()` 抛 `ExtensionPlatformMissingException` —— 不静默退化。
 * 理由：这些方法一旦不生效，扩展的路由/视图会**静默消失**，
 * 症状是「装了扩展但页面 404」，极难查。宁可启动就报清楚的错。
 */
interface ExtensionPlatform
{
    /**
     * 注册扩展路由（自动带后台前缀与鉴权中间件）。
     *
     * @param  \Closure():void  $callback
     * @param  string  $prefix  后台路由前缀（多应用下每个后台不同）
     */
    public function routes(Extension $extension, \Closure $callback, string $prefix): void;

    /**
     * 注册扩展的视图命名空间。
     */
    public function views(string $namespace, string $path): void;

    /**
     * 注册扩展的翻译命名空间。
     */
    public function translations(string $namespace, string $path): void;

    /**
     * 记录扩展启动失败。
     *
     * 实现**必须自己吞掉异常** —— 它是在失败隔离路径上调用的，
     * 再抛一次会把「一个扩展坏了」升级成「整个后台起不来」。
     */
    public function logFailure(string $name, string $phase, \Throwable $e): void;
}
