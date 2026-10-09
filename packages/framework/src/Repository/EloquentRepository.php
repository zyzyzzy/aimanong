<?php

declare(strict_types=1);

namespace Aimanong\Repository;

use Aimanong\Ai\ScopeHooks;
use Aimanong\Contracts\Repository;
use Aimanong\Contracts\Repository as RepositoryContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * 用契约实现包装一个仓库。
     *
     * 场景：用户绑定了自定义的 Repository 实现（如 API 数据源），
     * 但 Controller 的类型声明是 EloquentRepository。
     * 这里把契约调用代理过去，不改变用户的实现。
     *
     * @param  class-string<Model>  $model
     */
    public static function fromContract(Repository $inner, string $model): self
    {
        $repo = new self($model);

        $repo->inner = $inner;

        return $repo;
    }

    /**
     * 用户提供的契约实现（存在时优先使用）。
     */
    protected ?Repository $inner = null;

    /**
     * 分页列表。支持搜索、排序、筛选。
     *
     * @param  array<string, mixed>  $params
     * @param  int|null  $defaultPerPage  Resource 声明的每页条数，请求未指定时使用
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(array $params = [], ?int $defaultPerPage = null): LengthAwarePaginator
    {
        if ($this->inner !== null) {
            return $this->inner->paginate($params, $defaultPerPage);
        }

        $query = $this->model::query();

        // 数据作用域（多租户等）—— 官方拦截点，用户通过 ScopeHooks 注册
        ScopeHooks::applyQuery($query);

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
        if ($this->inner !== null) {
            return $this->inner->create($data);
        }

        return $this->model::query()->create(
            ScopeHooks::applyWriting($data)
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int|string $id, array $data): Model
    {
        if ($this->inner !== null) {
            return $this->inner->update($id, $data);
        }

        $model = $this->find($id);
        $model->update($data);

        return $model->fresh() ?? $model;
    }

    public function delete(int|string $id): bool
    {
        if ($this->inner !== null) {
            return $this->inner->delete($id);
        }

        return (bool) $this->find($id)->delete();
    }

    public function find(int|string $id): Model
    {
        if ($this->inner !== null) {
            return $this->inner->find($id);
        }

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
                if (! is_string($column) || $column === '') {
                    continue;
                }

                /*
                 * 关联列（如 product.name）必须走 whereHas ——
                 * 直接 orWhere('product.name', ...) 会被 SQL 当成列名，
                 * 报 "no such column"，且会连带拖垮同一查询里的其它条件。
                 */
                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);

                    $q->orWhereHas($relation, function (Builder $sub) use ($field, $keyword): void {
                        $sub->where($field, 'like', "%{$keyword}%");
                    });

                    continue;
                }

                $q->orWhere($column, 'like', "%{$keyword}%");
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

        /*
         * 关联路径排序需走 join 语义 —— 用子查询排序保持结果集不变。
         * 直接 orderBy('product.name') 会报 no such column。
         */
        if (str_contains($sort, '.')) {
            [$relation, $field] = explode('.', $sort, 2);

            $query->orderBy(
                $this->relationSubquery($relation, $field),
                $direction
            );

            return;
        }

        $query->orderBy($sort, $direction);
    }

    /**
     * 构造关联字段的排序子查询。
     *
     * 用于 orderBy —— 避免 join 导致的分页计数偏差。
     */
    protected function relationSubquery(string $relation, string $field): \Illuminate\Database\Query\Builder
    {
        /** @var Model $instance */
        $instance = new $this->model;
        $related = $instance->{$relation}();

        // 仅支持 belongsTo / hasOne —— 这类关联有唯一目标行
        if (! $related instanceof BelongsTo
            && ! $related instanceof HasOne) {
            throw new \RuntimeException(
                "关联 [{$relation}] 是 ".(new \ReflectionClass($related))->getShortName()
                .'，不支持排序（仅支持 belongsTo / hasOne）'
            );
        }

        /** @var Model $target */
        $target = $related->getRelated();

        return $target->newQuery()
            ->select($field)
            ->whereColumn(
                $target->getTable().'.'.$related->getOwnerKeyName(),
                $instance->getTable().'.'.$related->getForeignKeyName()
            )
            ->limit(1)
            ->getQuery();
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

            // 关联路径筛选走 whereHas
            if (str_contains($column, '.')) {
                [$relation, $field] = explode('.', $column, 2);

                $query->whereHas($relation, function (Builder $sub) use ($field, $value): void {
                    $sub->where($field, $value);
                });

                continue;
            }

            $query->where($column, $value);
        }
    }
}
