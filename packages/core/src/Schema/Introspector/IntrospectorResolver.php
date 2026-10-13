<?php

declare(strict_types=1);

namespace Aimanong\Schema\Introspector;

use Aimanong\Contracts\SchemaIntrospector;
use Aimanong\Support\Drivers;

/**
 * 内省实现的解析器。
 *
 * 驱动由框架包在启动时注册（`Drivers::registerIntrospector()`）。
 * 这里**不**用 `class_exists` 探测具体实现 ——
 * 探测意味着 core 要知道驱动的类名，那就还是耦合。
 *
 * 没注册时返回 `NullIntrospector`：幽灵列检测在无数据库环境下
 * 本就跳过，抛异常会让纯静态自省（产物生成）整体不可用。
 */
final class IntrospectorResolver
{
    /**
     * 显式指定优先，其次取已注册的驱动，最后退回空实现。
     */
    public static function resolve(?SchemaIntrospector $explicit = null): SchemaIntrospector
    {
        return $explicit ?? Drivers::introspector() ?? new NullIntrospector;
    }
}
