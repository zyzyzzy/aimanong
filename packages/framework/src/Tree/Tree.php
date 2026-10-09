<?php

declare(strict_types=1);

namespace Aimanong\Tree;

/**
 * 树形结构定义。
 *
 * 用于无限极分类、部门层级、菜单等场景。
 *
 * AI-First 约定：链式扁平、无重载、无闭包。
 */
class Tree
{
    protected string $parentColumn = 'parent_id';

    protected string $orderColumn = 'sort';

    protected string $titleColumn = 'name';

    protected bool $draggable = true;

    protected int $maxDepth = 0;

    /**
     * 父级字段名。
     */
    public function parentColumn(string $column): static
    {
        $this->parentColumn = $column;

        return $this;
    }

    /**
     * 排序字段名。
     */
    public function orderColumn(string $column): static
    {
        $this->orderColumn = $column;

        return $this;
    }

    /**
     * 显示标题字段名（用于节点文字）。
     */
    public function titleColumn(string $column): static
    {
        $this->titleColumn = $column;

        return $this;
    }

    /**
     * 是否允许拖拽排序。
     */
    public function draggable(bool $value = true): static
    {
        $this->draggable = $value;

        return $this;
    }

    /**
     * 最大层级（0 = 不限制）。
     */
    public function maxDepth(int $depth): static
    {
        $this->maxDepth = $depth;

        return $this;
    }

    public function getParentColumn(): string
    {
        return $this->parentColumn;
    }

    public function getOrderColumn(): string
    {
        return $this->orderColumn;
    }

    public function getTitleColumn(): string
    {
        return $this->titleColumn;
    }

    public function isDraggable(): bool
    {
        return $this->draggable;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'parentColumn' => $this->parentColumn,
            'orderColumn' => $this->orderColumn,
            'titleColumn' => $this->titleColumn,
            'draggable' => $this->draggable,
            'maxDepth' => $this->maxDepth,
        ];
    }
}
