<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 框架无关的数据源契约。
 *
 * 与 Laravel 侧的 `Aimanong\Contracts\Repository` 的关系：
 * 那个契约的返回类型绑死了 `LengthAwarePaginator` / `Eloquent\Model`，
 * 保留原样不动（Laravel 用户零影响）。本契约是它的框架无关版本，
 * 由各驱动包实现。
 *
 * ## 实现约定
 *
 * - `find()` **找不到必须抛异常**，不能返回 null。
 *   ThinkPHP 的 `Model::find()` 找不到返回 null —— 直接透传就是静默失败，
 *   正是宪法第三条禁止的形态。
 * - `paginate()` 的 `$params` 是松散的查询意图
 *   （`keyword` / `searchable` / `sort` / `direction` / `filters` / `ids` /
 *   `relations` / `per_page`），由实现各自翻译成自己的查询构造。
 */
interface DataSource
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function paginate(array $params = [], ?int $defaultPerPage = null): PageResult;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): object;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int|string $id, array $data): object;

    public function delete(int|string $id): bool;

    public function find(int|string $id): object;
}
