<?php

declare(strict_types=1);

namespace Aimanong\Repository;

use Aimanong\Contracts\Repository as RepositoryContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Eloquent 数据源。
 *
 * 松耦合设计：Controller 只依赖契约，可替换为 API / 数组等其它数据源。
 */
class EloquentRepository implements RepositoryContract
{
    /** @var Builder<Model> */
    protected Builder $query;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        protected string $model,
    ) {
        $this->query = $this->model::query();
    }

    /**
     * 分页列表。支持搜索、排序、筛选。
     *
     * @param  array<string, mixed>  $params
     * @param  int|null  $defaultPerPage  Resource 声明的每页条数，请求未指定时使用
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(array $params = [], ?int $defaultPerPage = null): LengthAwarePaginator
    {
        $query = $this->model::query();

        // 关联列需要预加载，否则会 N+1
        $this->applyEagerLoad($query, $params);
        $this->applySearch($query, $params);
        $this->applySort($query, $params);
        $this->applyFilters($query, $params);

        // 优先级：请求参数 > Resource 声明 > 框架默认
        $perPage = is_numeric($params['per_page'] ?? null)
            ? (int) $params['per_page']
            : ($defaultPerPage ?? (int) ($params['perPage'] ?? 20));

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Model
    {
        return $this->model::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int|string $id, array $data): Model
    {
        $model = $this->find($id);
        $model->update($data);

        return $model->fresh() ?? $model;
    }

    public function delete(int|string $id): bool
    {
        return (bool) $this->find($id)->delete();
    }

    public function find(int|string $id): Model
    {
        $model = $this->model::query()->find($id);

        if ($model === null) {
            throw new ModelNotFoundException(
                "记录不存在: {$this->model}#{$id}"
            );
        }

        return $model;
    }

    /**
     * 预加载关联。
     *
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $params
     */
    protected function applyEagerLoad(Builder $query, array $params): void
    {
        $relations = $params['relations'] ?? null;

        if (! is_array($relations) || $relations === []) {
            return;
        }

        // 'category.name' → 预加载 'category'
        $with = [];

        foreach ($relations as $path) {
            if (is_string($path) && $path !== '') {
                $with[] = explode('.', $path)[0];
            }
        }

        if ($with !== []) {
            $query->with(array_unique($with));
        }
    }

    /**
     * 快捷搜索：对 searchable 列做 OR like。
     *
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $params
     */
    protected function applySearch(Builder $query, array $params): void
    {
        $keyword = $params['keyword'] ?? $params['search'] ?? null;

        // 兼容 Stringable 等可字符串化对象。
        // 曾因只判断 is_string，导致 MCP 传入 Stringable 时搜索被静默忽略，
        // 返回全表数据 —— 静默失败比报错更危险，AI 会误判数据形态。
        if ($keyword instanceof \Stringable) {
            $keyword = (string) $keyword;
        }

        if (! is_string($keyword) || $keyword === '') {
            return;
        }

        $columns = $params['searchable'] ?? [];

        if (! is_array($columns) || $columns === []) {
            return;
        }

        $query->where(function (Builder $q) use ($columns, $keyword): void {
            foreach ($columns as $column) {
                if (is_string($column)) {
                    $q->orWhere($column, 'like', "%{$keyword}%");
                }
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $params
     */
    protected function applySort(Builder $query, array $params): void
    {
        $sort = $params['sort'] ?? null;

        if (! is_string($sort) || $sort === '') {
            return;
        }

        $direction = ($params['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sort, $direction);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $params
     */
    protected function applyFilters(Builder $query, array $params): void
    {
        $filters = $params['filters'] ?? null;

        if (! is_array($filters)) {
            return;
        }

        foreach ($filters as $column => $value) {
            if (! is_string($column) || $value === null || $value === '') {
                continue;
            }

            $query->where($column, $value);
        }
    }
}
