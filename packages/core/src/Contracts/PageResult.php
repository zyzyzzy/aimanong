<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 框架无关的分页结果。
 *
 * 只暴露 Controller 真正用到的 5 个读方法 —— 契约越小，驱动越好实现。
 *
 * ## 为什么不用各框架自带的分页对象
 *
 * Laravel 返回 `LengthAwarePaginator`，ThinkPHP 返回
 * `think\paginator\driver\Bootstrap`；两者的方法名还不一样
 * （前者 `perPage()`，后者只有 `listRows()`）。
 * 让 core 依赖其中任何一个，就等于把 core 绑到那个框架上。
 */
interface PageResult
{
    /**
     * @return array<int, object>
     */
    public function items(): array;

    public function total(): int;

    public function perPage(): int;

    public function currentPage(): int;

    public function lastPage(): int;
}
