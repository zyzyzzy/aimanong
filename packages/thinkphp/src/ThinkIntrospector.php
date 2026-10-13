<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Contracts\SchemaIntrospector;
use think\db\Query;
use think\facade\Db;
use think\helper\Str;
use think\Model;
use think\model\Relation;
use think\model\relation\BelongsTo;

/**
 * ThinkORM 内省 —— `Contracts\SchemaIntrospector` 的 ThinkPHP 实现。
 *
 * 幽灵列检测（宪法第三条「零隐式」的主要执行者）在 Laravel 侧靠
 * Eloquent 的 `Schema` 门面与 `Model` API；TP 侧没有这些东西，
 * 但检测本身必须**一样生效** —— 否则换了框架，声明了不存在的列
 * 又会静默通过，AI 会基于错误 schema 继续写代码。
 *
 * ## 两端 API 对照（都做了能力探测，不同 TP 小版本有差异）
 *
 * | 用途 | Laravel | ThinkPHP |
 * |---|---|---|
 * | 取表名 | `$model->getTable()` | 同名 |
 * | 表是否存在 | `Schema::hasTable()` | `Db::connect()->getTables()` |
 * | 真实列 | `Schema::getColumnListing()` | `Query::getFields()` |
 * | 是不是关联 | `instanceof Relation` | `instanceof think\model\relation\*` |
 * | 访问器 | `hasGetMutator()` / `hasAttributeGetMutator()` | `getXxxAttr()` 方法 |
 */
class ThinkIntrospector implements SchemaIntrospector
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
        try {
            return in_array($table, $this->tables(), true);
        } catch (\Throwable) {
            // 无数据库连接等 —— 交给调用方按「表不存在」处理
            return false;
        }
    }

    /**
     * 表的真实列名。
     *
     * ⚠️ **两端返回的形状不同，这里踩过**：
     *
     *   Laravel `Schema::getColumnListing('t')` → `['id', 'name', ...]`   平铺列表
     *   ThinkPHP `Query::getFields()`           → `['id' => [...], 'name' => [...]]`  映射
     *
     * 直接 `array_values()` 会得到「一堆数组」，于是
     * `in_array('name', $columns)` 永远为 false —— **幽灵列检测全线失效**：
     * 每一个真实列都会被报成幽灵列（或反过来全放过），
     * 而错误信息里那份「可用列」也会变成 Array、Array、Array。
     *
     * 所以这里取键，并对「某些版本直接给平铺列表」的情况做兼容。
     *
     * @return array<int, string>
     */
    public function columnsOf(string $table): array
    {
        try {
            /** @var mixed $fields */
            $fields = Db::table($table)->getFields();
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($fields) || $fields === []) {
            return [];
        }

        $values = array_values($fields);

        // 形状 A：平铺的字符串列表
        if (isset($values[0]) && is_string($values[0])) {
            return array_map('strval', $values);
        }

        // 形状 B：字段名 => 字段信息
        $names = [];

        foreach (array_keys($fields) as $key) {
            if (is_string($key) && $key !== '') {
                $names[] = $key;
            }
        }

        return $names;
    }

    /**
     * 是不是关联对象。
     *
     * ⚠️ 基类是 `think\model\Relation`，**不在** `relation\` 子命名空间下。
     * 实测踩到：写成 `think\model\relation\Relation` 会永远返回 false，
     * 于是 `foo.bar` 这样的关联列全被判成幽灵列。继承链是
     * `BelongsTo → OneToOne → think\model\Relation`。
     */
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
                return $result->getModel()::class;
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

        // ① TP 的访问器写法：getXxxAttr()
        if (method_exists($model, 'get'.Str::studly($name).'Attr')) {
            return true;
        }

        // ② 已声明的类型转换（$type 属性）
        $type = $this->castsOf($model);

        if (array_key_exists($name, $type)) {
            return true;
        }

        // ③ 同名无参方法（关系 / 计算属性）
        if (method_exists($model, $name)) {
            try {
                return (new \ReflectionMethod($model, $name))->getNumberOfRequiredParameters() === 0;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * 库里的表清单。
     *
     * `getTables()` 在 think-orm 的部分版本/驱动上不存在，所以做能力探测，
     * 而不是直接假设 —— 缺了就让 `hasTable()` 返回 false，
     * 检测自动跳过（与无数据库连接时的行为一致）。
     *
     * @return array<int, string>
     */
    protected function tables(): array
    {
        $connection = Db::connect();

        if (! method_exists($connection, 'getTables')) {
            return [];
        }

        /** @var array<int, string> $tables */
        $tables = $connection->getTables();

        return array_values($tables);
    }

    /**
     * 取模型的 `$type`（TP 的类型转换声明）。
     *
     * 它是 protected 属性，TP 没有公开读接口，只能反射。
     *
     * @return array<string, mixed>
     */
    protected function castsOf(object $model): array
    {
        try {
            $ref = new \ReflectionClass($model);

            if (! $ref->hasProperty('type')) {
                return [];
            }

            $prop = $ref->getProperty('type');
            $prop->setAccessible(true);
            $value = $prop->getValue($model);

            return is_array($value) ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
