<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Contracts\PageResult;
use think\Paginator;

/**
 * ThinkPHP 分页结果 → 框架无关的 `PageResult`。
 *
 * ## 为什么必须包一层
 *
 * Laravel 的 `LengthAwarePaginator` 有 `perPage()`，
 * ThinkPHP 的分页对象**没有** —— 它叫 `listRows()`。
 * 两边的类名与方法名都不同，所以 core 不能直接用任何一个。
 */
final class ThinkPageResult implements PageResult
{
    /**
     * @param  Paginator<int, object>  $paginator
     */
    public function __construct(private readonly Paginator $paginator) {}

    /**
     * @return array<int, object>
     */
    public function items(): array
    {
        /** @var array<int, object> $items */
        $items = $this->paginator->items();

        return array_values($items);
    }

    public function total(): int
    {
        return (int) $this->paginator->total();
    }

    public function perPage(): int
    {
        // Laravel 叫 perPage()，TP 叫 listRows() —— 这是最容易漏的一处差异
        return (int) $this->paginator->listRows();
    }

    public function currentPage(): int
    {
        return (int) $this->paginator->currentPage();
    }

    public function lastPage(): int
    {
        return (int) $this->paginator->lastPage();
    }
}
