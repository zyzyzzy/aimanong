<?php

declare(strict_types=1);

namespace Aimanong\Grid;

use Aimanong\Grid\Concerns\CanExport;
use Aimanong\Schema\Ast\ColumnNode;

/**
 * 列表页定义。
 *
 * AI-First 约定：所有配置走链式调用，禁止嵌套闭包。
 */
class Grid
{
    use CanExport;

    /**
     * @var array<string, Column>
     */
    protected array $columns = [];

    /**
     * @var array<int, string>
     */
    protected array $batchActions = [];

    protected int $perPage = 20;

    protected bool $withActions = true;

    /**
     * 定义一列。签名唯一，无重载。
     */
    public function column(string $name, ?string $label = null): Column
    {
        $column = new Column($name, $label);
        $this->columns[$name] = $column;

        return $column;
    }

    /**
     * 批量定义多列（AI 友好：一次列出来，避免重复调用）。
     *
     * @param  array<int, string>  $names
     */
    public function columns(array $names): static
    {
        foreach ($names as $name) {
            $this->column($name);
        }

        return $this;
    }

    public function perPage(int $count): static
    {
        $this->perPage = $count;

        return $this;
    }

    public function actions(bool $value = true): static
    {
        $this->withActions = $value;

        return $this;
    }

    /**
     * @param  array<int, string>  $actions
     */
    public function batchActions(array $actions): static
    {
        $this->batchActions = $actions;

        return $this;
    }

    /**
     * @return array<int, ColumnNode>
     */
    public function toNodes(): array
    {
        return array_map(
            fn (Column $c): ColumnNode => $c->toNode(),
            array_values($this->columns)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'perPage' => $this->perPage,
            'withActions' => $this->withActions,
            'batchActions' => $this->batchActions,
            'exportable' => $this->exportable,
            'exportColumns' => $this->exportColumns(),
            'columns' => array_map(fn (ColumnNode $n): array => $n->toArray(), $this->toNodes()),
        ];
    }
}
