<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Contracts\ApplicationContext;

/**
 * 应用上下文的**显式空实现**：只提供固定的前缀。
 *
 * ## ⚠️ 这个文件是补出来的（2026-10-16）
 *
 * 它原先和 `NullGuard` / `NullExtensions` 一起塞在 `NullRuntime.php` 里 ——
 * **文件名与类名全都不匹配**，于是 PSR-4 自动加载器
 * （`Aimanong\ThinkPHP\NullContext` → `src/NullContext.php`）**永远找不到它**。
 *
 * 它之所以一直「能用」，是因为 composer 的 **classmap**（optimize-autoloader
 * 的产物）会把整个文件扫进去、顺便定义所有类。一旦哪条代码路径在
 * `NullRuntime` 被加载**之前**就要用 `NullContext`，就会
 * `Class "Aimanong\ThinkPHP\NullContext" not found`。
 *
 * 实测就是这么炸的：给 TP 补扩展机制时，扩展的 `boot()` 里读了一次
 * `Aimanong::context()->prefix()` —— 而 `ExtensionManager` 的失败隔离
 * 把异常**吞掉了**，症状是「扩展静默不生效」，日志里只有一行
 * 「boot() 失败」。
 *
 * 教训：一个文件一个类，不是因为洁癖 —— 是因为 PSR-4 只认文件名。
 */
final class NullContext implements ApplicationContext
{
    public function __construct(private readonly string $prefix = '/admin') {}

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function guard(): NullGuard
    {
        return new NullGuard;
    }
}
