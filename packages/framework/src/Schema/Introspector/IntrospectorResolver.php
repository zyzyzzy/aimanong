<?php

declare(strict_types=1);

namespace Aimanong\Schema\Introspector;

use Aimanong\Contracts\SchemaIntrospector;
use Illuminate\Database\Eloquent\Model;

/**
 * 内省实现的解析器。
 *
 * 按「驱动是否可用」挑选实现。检测用 `class_exists` 而非
 * 容器绑定，因为 Schema 层要在 CLI / MCP / HTTP 三种入口下都能用，
 * 且必须能在**没有完整 Laravel 应用**的场景下工作（如 ai:verify 的单元测试）。
 */
final class IntrospectorResolver
{
    /**
     * 显式指定优先，其次按可用驱动挑选。
     */
    public static function resolve(?SchemaIntrospector $explicit = null): SchemaIntrospector
    {
        if ($explicit !== null) {
            return $explicit;
        }

        // Laravel / Eloquent 驱动可用
        if (class_exists(Model::class)) {
            return new EloquentIntrospector;
        }

        return new NullIntrospector;
    }
}
