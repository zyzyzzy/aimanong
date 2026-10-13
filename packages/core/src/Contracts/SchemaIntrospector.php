<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * Schema 内省契约 —— 把「问 ORM 要事实」这件事从 Schema 层摘出去。
 *
 * ## 为什么需要它
 *
 * `Schema\Compiler` 是四份产物的枢纽，也是宪法第三条「零隐式」的
 * 主要执行者（幽灵列检测）。但它原先直接依赖三个 Eloquent 类型：
 *
 *   - `Illuminate\Database\Eloquent\Model`      → 取表名
 *   - `Illuminate\Support\Facades\Schema`       → 查表是否存在、取真实列名
 *   - `Illuminate\Database\Eloquent\Relations\Relation` → 判断是不是关联
 *
 * 后果：换到别 ORM 后这段检测**静默失效** —— 声明了不存在的列
 * 不会再报错，AI 会基于错误 schema 继续写代码。
 * 幽灵列检测是框架最不能降级的能力，所以它必须走契约。
 *
 * ## 实现约定
 *
 * - `tableOf()` 无法判定时返回 `null`（不是空字符串）
 * - `columnsOf()` 返回真实列名列表；查不到时返回空数组
 * - 实现**允许抛异常**（如无数据库连接）—— 调用方决定如何处理
 */
interface SchemaIntrospector
{
    /**
     * 模型对应的表名。无法判定返回 null。
     */
    public function tableOf(object $model): ?string;

    /**
     * 表是否存在于当前连接。
     */
    public function hasTable(string $table): bool;

    /**
     * 表的真实列名。
     *
     * @return array<int, string>
     */
    public function columnsOf(string $table): array;

    /**
     * 某个值是不是「关联对象」。
     *
     * 用于区分 `foo()` 到底是普通方法还是关系定义 ——
     * 只判断方法存在会让 `foo.bar` 被误放行。
     */
    public function isRelation(mixed $value): bool;

    /**
     * 关联的目标模型类名；不是关联或定义有误时返回 null。
     *
     * 用于把 `category.name` 里的 `category` 解析成目标模型，
     * 进而判断 `name` 在目标表里是否真实存在。
     */
    public function relationTargetOf(object $model, string $relation): ?string;

    /**
     * 名字是否是模型上的虚拟属性。
     *
     * 覆盖：传统访问器(`getXxxAttribute`)、Laravel 9+ 访问器(`xxx(): Attribute`)、
     * 已声明的 casts、以及同名无参方法（视作关系/计算属性）。
     *
     * 注意：不能依赖 `hasAttribute()` —— 新建模型实例的属性数组为空，
     * 对任何字段都返回 false（踩过一次）。
     */
    public function isVirtualAttribute(object $model, string $name): bool;
}
