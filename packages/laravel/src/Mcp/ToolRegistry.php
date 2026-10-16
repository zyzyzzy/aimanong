<?php

declare(strict_types=1);

namespace Aimanong\Mcp;

use Laravel\Mcp\Server\Tool;

/**
 * MCP 工具注册表 —— 让**可选包**能往 Aimanong 的 MCP Server 里加工具。
 *
 * ## 为什么需要它
 *
 * `AimanongServer` 的工具是写死在 `protected array $tools` 里的。
 * 而像 `aimanong/aimind-client` 这样的可选包不能通过 composer 依赖
 * 反向注入（laravel 包不能 require 一个可选包 —— 那会让所有用户
 * 都被迫安装它）。
 *
 * 所以：可选包在 ServiceProvider 里调 `ToolRegistry::register(...)`，
 * `AimanongServer` 构造时合并进来。**没装那个包就不会有那些工具**，
 * 装了就有 —— 这正是可选包该有的行为。
 */
final class ToolRegistry
{
    /** @var array<int, class-string<Tool>> */
    private static array $tools = [];

    /**
     * @param  class-string<Tool>  ...$classes
     */
    public static function register(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (! class_exists($class)) {
                continue;
            }

            if (! in_array($class, self::$tools, true)) {
                self::$tools[] = $class;
            }
        }
    }

    /**
     * @return array<int, class-string<Tool>>
     */
    public static function all(): array
    {
        return self::$tools;
    }

    /**
     * 测试用 —— 清空注册表。
     */
    public static function reset(): void
    {
        self::$tools = [];
    }
}
