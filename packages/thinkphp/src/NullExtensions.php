<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

/**
 * 扩展管理器的**显式空实现**（尚未实现的运行时能力）。
 */
final class NullExtensions
{
    /**
     * @return array<int, string>
     */
    public function booted(): array
    {
        return [];
    }
}
