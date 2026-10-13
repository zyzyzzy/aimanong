<?php

declare(strict_types=1);

namespace Aimanong\Schema\Introspector;

use Aimanong\Contracts\SchemaIntrospector;

/**
 * 空内省实现 —— 没有任何驱动可用时的兜底。
 *
 * ## ⚠️ 它的存在意味着一项能力被关闭
 *
 * 用上它时，**幽灵列检测不会运行**：`hasTable()` 返回 false，
 * `Compiler::assertColumnsExist()` 会直接 return。
 * 这与拆包前的行为一致（那时没有 Eloquent 也是静默跳过）。
 *
 * 拆包完成后，正确做法是：驱动缺失时**报明确的错**，
 * 而不是静默降级。见 `多框架适配-拆包实施计划.md` 第 4 段。
 *
 * 现在保留静默是为让本段「行为零变化」，便于用现有测试证明重构无副作用。
 */
class NullIntrospector implements SchemaIntrospector
{
    public function tableOf(object $model): ?string
    {
        return null;
    }

    public function hasTable(string $table): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function columnsOf(string $table): array
    {
        return [];
    }

    public function isRelation(mixed $value): bool
    {
        return false;
    }

    public function isVirtualAttribute(object $model, string $name): bool
    {
        return false;
    }
}
