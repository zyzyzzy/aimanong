<?php

declare(strict_types=1);

namespace Aimanong\Schema\Introspector;

use Aimanong\Contracts\SchemaIntrospector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

/**
 * Eloquent 内省实现（Laravel 驱动）。
 *
 * **本文件里出现的全部 Illuminate 依赖集中在这一个类**，
 * 拆包时整个 `Schema/Introspector/` 目录搬到 `aimanong/laravel` 即可。
 *
 * 拆包前它是框架里最后 3 处 Schema 层耦合的归宿：
 * 原先散在 `Compiler::assertColumnsExist()` 和
 * `Compiler::isVirtualAttribute()` 里。
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
