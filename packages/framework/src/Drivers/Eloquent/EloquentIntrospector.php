<?php

declare(strict_types=1);

namespace Aimanong\Drivers\Eloquent;

use Aimanong\Contracts\SchemaIntrospector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

/**
 * Eloquent 内省实现（Laravel 驱动）。
 *
 * 放在 `Aimanong\Drivers\` 下是刻意的：**这个命名空间整体属于驱动层**，
 * 拆包时整个 `src/Drivers/` 搬到 `aimanong/laravel` 即可。
 * core 侧只有 `Contracts\SchemaIntrospector` 与 `NullIntrospector`。
 *
 * 本类收拢了原先散在 `Compiler::assertColumnsExist()` /
 * `Compiler::isVirtualAttribute()` 里的 Eloquent 耦合。
 */
class EloquentIntrospector implements SchemaIntrospector
{
    public function tableOf(object $model): ?string
    {
        if (! $model instanceof Model) {
            return null;
        }

        return $model->getTable();
    }

    public function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /**
     * @return array<int, string>
     */
    public function columnsOf(string $table): array
    {
        /** @var array<int, string> $columns */
        $columns = Schema::getColumnListing($table);

        return array_values($columns);
    }

    public function isRelation(mixed $value): bool
    {
        return $value instanceof Relation;
    }

    public function relationTargetOf(object $model, string $relation): ?string
    {
        if (! $model instanceof Model) {
            return null;
        }

        try {
            $result = $model->{$relation}();

            if ($result instanceof Relation) {
                return $result->getRelated()::class;
            }
        } catch (\Throwable) {
            // 关联定义有误 —— 交给上层校验报告
        }

        return null;
    }

    public function isVirtualAttribute(object $model, string $name): bool
    {
        if (! $model instanceof Model) {
            return false;
        }

        /*
         * 访问器：两种写法都要认。
         *
         * Laravel 9+ 推荐 `protected function xxx(): Attribute`，
         * 对应的检测 API 是 hasAttributeGetMutator() ——
         * 只检查传统写法 hasGetMutator() 会把现代写法误判成「幽灵列」
         * （CMS 场景验证发现）。
         */
        if ($model->hasGetMutator($name)) {
            return true;
        }

        if ($model->hasAttributeGetMutator($name)) {
            return true;
        }

        // 已声明 casts
        if ($model->hasCast($name)) {
            return true;
        }

        // 已定义的同名关系 / 计算属性（无参方法）
        if (method_exists($model, $name)) {
            try {
                return (new \ReflectionMethod($model, $name))->getNumberOfRequiredParameters() === 0;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
}
