<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * 数据源契约。
 *
 * 松耦合：Controller 依赖此契约而非具体 ORM，
 * 因此可替换成 API / 数组 / 静态文件等数据源。
 */
interface Repository
{
    /**
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(array $params = [], ?int $defaultPerPage = null): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Model;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int|string $id, array $data): Model;

    public function delete(int|string $id): bool;

    public function find(int|string $id): Model;
}
