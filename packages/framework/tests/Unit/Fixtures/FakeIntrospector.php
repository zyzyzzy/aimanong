<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Contracts\SchemaIntrospector;

/**
 * 可编程的内省实现（测试用）。
 *
 * 让 Compiler 的幽灵列检测可以在**不连数据库**的情况下被完整测试 ——
 * 这是原先缺失的覆盖：所有单测都依赖「无 DB → 跳过检测」的早退路径，
 * 于是检测逻辑本身从未被跑过。
 */
class FakeIntrospector implements SchemaIntrospector
{
    /**
     * @param  array<string, array<int, string>>  $tables  表名 => 列清单
     * @param  array<int, string>  $virtual  视为虚拟属性的名字
     * @param  array<int, string>  $relations  视为关联的方法名
     * @param  array<string, string>  $relationTargets  关联名 => 目标模型类名
     */
    public function __construct(
        public array $tables = [],
        public array $virtual = [],
        public array $relations = [],
        public ?string $table = null,
        public array $relationTargets = [],
    ) {}

    public function tableOf(object $model): ?string
    {
        return $this->table;
    }

    public function hasTable(string $table): bool
    {
        return array_key_exists($table, $this->tables);
    }

    /**
     * @return array<int, string>
     */
    public function columnsOf(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    public function isRelation(mixed $value): bool
    {
        return is_string($value) && in_array($value, $this->relations, true);
    }

    public function relationTargetOf(object $model, string $relation): ?string
    {
        return $this->relationTargets[$relation] ?? null;
    }

    public function isVirtualAttribute(object $model, string $name): bool
    {
        return in_array($name, $this->virtual, true);
    }
}
