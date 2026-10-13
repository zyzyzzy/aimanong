<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Ai\ScopeHooks;
use Aimanong\Contracts\DataSource;
use Aimanong\Contracts\PageResult;
use think\db\exception\ModelNotFoundException;
use think\db\Query;
use think\db\Raw;
use think\facade\Db;
use think\Model;

/**
 * ThinkORM 数据源 —— `Contracts\DataSource` 的 ThinkPHP 实现。
 *
 * ## 与 Laravel 的 `EloquentRepository` 的差异（全部实测过）
 *
 * | Laravel | ThinkPHP | 说明 |
 * |---|---|---|
 * | `Model::query()` | `(new Model)->db()` | TP 的 Model **没有**静态 `query()`，硬调会掉进 `__callStatic` → `Query::__call` → `DbException: method not exist`（是运行时异常，不是致命错误，极易误判） |
 * | `ModelNotFoundException`（`Illuminate\…`） | `think\db\exception\ModelNotFoundException` | 同名类在 **db** 子命名空间下 |
 * | `find()` 找不到自动抛 | `find()` 返回 `null` | **必须自己抛**，否则是静默返回 null |
 * | `paginate()->perPage()` | `paginate()->listRows()` | 方法名不同 |
 * | `orderBy($subqueryBuilder)` | `order(Db::raw('(select … limit 1)'))` | TP 的 `order()` 只吃字符串，没有「子查询 Builder」这个口子 |
 *
 * 这五处都是 PoC 实测出来的，不是推测。
 */
class ThinkDataSource implements DataSource
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(protected string $model) {}

    public function paginate(array $params = [], ?int $defaultPerPage = null): PageResult
    {
        $query = $this->query();

        /*
         * 数据作用域（多租户 / 行级隔离）—— **必须在所有条件之前**。
         *
         * ⚠️ 这是本次全面验收发现的**最高危缺口**：Laravel 侧
         * EloquentRepository 有 4 处 ScopeHooks，而这里此前 0 处 ——
         * 配了多租户后 TP 侧查询不做任何收窄，**跨租户数据全量返回**。
         * 而且它在 200 响应里完全看不出来（这正是它危险的原因）。
         */
        ScopeHooks::applyQuery($query);

        $this->applyEagerLoad($query, $params);
        $this->applySearch($query, $params);
        $this->applySort($query, $params);
        $this->applyFilters($query, $params);
        $this->applyIds($query, $params);

        // 优先级：请求参数 > Resource 声明 > 框架默认
        $perPage = is_numeric($params['per_page'] ?? null)
            ? (int) $params['per_page']
            : ($defaultPerPage ?? (int) ($params['perPage'] ?? 20));

        return new ThinkPageResult($query->paginate($perPage));
    }

    public function create(array $data): object
    {
        // 写入作用域（多租户：自动补 tenant_id）—— 与查询作用域成对，
        // 少了它就会出现「读得到、写不进」或更糟的「写到别人的租户下」
        $data = ScopeHooks::applyWriting($data);

        // TP 的 create 是**真正的静态方法**（不像 query() 是 __callStatic 转发）
        return $this->model::create($data);
    }

    public function update(int|string $id, array $data): object
    {
        $model = $this->find($id);
        $model->save(ScopeHooks::applyWriting($data));

        // Eloquent 有 fresh()，TP 没有 —— 重新查一次
        return $this->find($id);
    }

    public function delete(int|string $id): bool
    {
        return (bool) $this->find($id)->delete();
    }

    public function find(int|string $id): object
    {
        $model = $this->model::find($id);

        /*
         * ⚠️ TP 的 find() 找不到**返回 null**，不像 Eloquent 会自动抛。
         * 不在这里抛，调用方就会拿到 null，然后在别处以一个
         * 莫名其妙的方式炸掉（或者在界面上显示空白）—— 静默失败。
         */
        if ($model === null) {
            throw new ModelNotFoundException("记录不存在: {$this->model}#{$id}");
        }

        return $model;
    }

    /**
     * 查询入口。
     *
     * ⚠️ 差异点：Eloquent 用静态 `Model::query()`；TP 的 Model 没有这个
     * 静态方法，`$model::query()` 会掉进 `__callStatic` → `Query::__call`
     * 并抛 `DbException: method not exist`。TP 的正确入口是 `(new Model)->db()`。
     */
    protected function query(): Query
    {
        return (new $this->model)->db();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function applyEagerLoad(Query $query, array $params): void
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
     * Eloquent：`where(fn) + orWhere(col,'like',..) + orWhereHas(..)`
     * ThinkPHP：`where(fn) + whereOr(col,'like',..) + hasWhere(.., 'OR')`
     *
     * @param  array<string, mixed>  $params
     */
    protected function applySearch(Query $query, array $params): void
    {
        $keyword = $params['keyword'] ?? $params['search'] ?? null;

        // 兼容 Stringable：只判 is_string 会让 MCP 传入的 Stringable 被静默忽略
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

        $query->where(function (Query $q) use ($columns, $keyword): void {
            foreach ($columns as $column) {
                if (! is_string($column) || $column === '') {
                    continue;
                }

                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);

                    // Eloquent: orWhereHas ｜ TP: hasWhere(..., 'OR')
                    $q->hasWhere($relation, function (Query $sub) use ($field, $keyword): void {
                        $sub->where($field, 'like', "%{$keyword}%");
                    }, 'OR');

                    continue;
                }

                $q->whereOr($column, 'like', "%{$keyword}%");
            }
        });
    }

    /**
     * 排序。
     *
     * 关联列排序是两端差异最大的一处：
     * Eloquent 能把一个子查询 Builder 交给 `orderBy()`，
     * TP 的 `order()` 只接受字符串 —— 必须自己拼 SQL 交给 `Db::raw()`。
     *
     * @param  array<string, mixed>  $params
     */
    protected function applySort(Query $query, array $params): void
    {
        $sort = $params['sort'] ?? null;

        if (! is_string($sort) || $sort === '') {
            return;
        }

        $direction = ($params['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if (str_contains($sort, '.')) {
            [$relation, $field] = explode('.', $sort, 2);

            $query->order($this->relationSubquery($relation, $field), $direction);

            return;
        }

        $query->order($sort, $direction);
    }

    /**
     * 关联字段排序用的子查询。
     *
     * Eloquent 版靠 `BelongsTo` / `HasOne` 的
     * `getRelated()` / `getOwnerKeyName()` / `getForeignKeyName()`；
     * TP 的关联对象叫 `getModel()` / `getLocalKey()` / `getForeignKey()`，
     * 且**没有「返回 Builder」的口子**，只能拼 SQL。
     */
    protected function relationSubquery(string $relation, string $field): Raw
    {
        $instance = new $this->model;

        if (! method_exists($instance, $relation)) {
            throw new \RuntimeException("关联 [{$relation}] 不存在");
        }

        $related = $instance->{$relation}();

        if (! $related instanceof \think\model\relation\BelongsTo
            && ! $related instanceof \think\model\relation\HasOne) {
            throw new \RuntimeException(
                "关联 [{$relation}] 是 ".(new \ReflectionClass($related))->getShortName()
                .'，不支持排序（仅支持 belongsTo / hasOne）'
            );
        }

        $target = $related->getModel();

        $sql = sprintf(
            '(select %s from %s where %s.%s = %s.%s limit 1)',
            $this->quote($field),
            $this->quote($target->getTable()),
            $this->quote($target->getTable()),
            $this->quote($related->getLocalKey()),
            $this->quote($instance->getTable()),
            $this->quote($related->getForeignKey())
        );

        return Db::raw($sql);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function applyFilters(Query $query, array $params): void
    {
        $filters = $params['filters'] ?? null;

        if (! is_array($filters)) {
            return;
        }

        foreach ($filters as $column => $value) {
            if (! is_string($column) || $value === null || $value === '') {
                continue;
            }

            if (str_contains($column, '.')) {
                [$relation, $field] = explode('.', $column, 2);

                // Eloquent: whereHas ｜ TP: hasWhere
                $query->hasWhere($relation, function (Query $sub) use ($field, $value): void {
                    $sub->where($field, $value);
                });

                continue;
            }

            $query->where($column, $value);
        }
    }

    /**
     * 按主键集合过滤（批量导出选中项用）。
     *
     * @param  array<string, mixed>  $params
     */
    protected function applyIds(Query $query, array $params): void
    {
        $ids = $params['ids'] ?? null;

        if (! is_array($ids) || $ids === []) {
            return;
        }

        $probe = new $this->model;

        // Eloquent: getKeyName() ｜ TP: getPk()
        $query->whereIn($probe->getPk(), $ids);
    }

    /**
     * 字段引用符随驱动变化。这里只支持反引号系（MySQL / SQLite）。
     */
    protected function quote(string $identifier): string
    {
        return '`'.str_replace('`', '', $identifier).'`';
    }
}
