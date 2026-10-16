<?php

declare(strict_types=1);

namespace Aimanong\Extend;

/**
 * 扩展平台的**静态持有者**。
 *
 * `Extension` 基类在 core，而它的 `views()` / `translations()` / `routes()`
 * 是 `protected` 且**没有构造函数注入的机会**（用户扩展类的构造函数
 * 签名是自由的，框架不能要求它接收平台对象）。
 *
 * 所以只能走静态注入 —— 驱动在启动时 `use()` 自己的平台实现。
 *
 * 与 `Aimanong\Ai\CapabilitiesRuntime` 是同一个模式。
 */
final class ExtensionRuntime
{
    private static ?ExtensionPlatform $platform = null;

    public static function use(ExtensionPlatform $platform): void
    {
        self::$platform = $platform;
    }

    /**
     * 当前平台。
     *
     * ⚠️ 未注册时**抛异常**，不静默退化。
     *
     * 理由：这几个方法一旦不生效，扩展的路由/视图会**静默消失**，
     * 症状是「装了扩展但页面 404」—— 极难查。
     * 宁可启动就报一个说得清的错。
     */
    public static function current(): ExtensionPlatform
    {
        return self::$platform ?? throw new \RuntimeException(
            '扩展平台未注册：驱动必须在启动时调用 '
            .'Aimanong\\Extend\\ExtensionRuntime::use(...)。'
            .'（Laravel: AimanongServiceProvider；ThinkPHP: ThinkService）'
        );
    }

    public static function registered(): bool
    {
        return self::$platform !== null;
    }

    /**
     * 复位（单元测试用 —— 静态状态会跨用例泄漏）。
     */
    public static function reset(): void
    {
        self::$platform = null;
    }
}
