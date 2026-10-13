<?php

declare(strict_types=1);

namespace Aimanong\Grid\Concerns;

/**
 * 导出配置。
 *
 * 作为 trait 混入 Grid，避免 Grid 类膨胀。
 *
 * 设计要点：**导出的列复用 Grid 已声明的列**，
 * 不另开一套定义 —— 否则又是"两处定义，迟早分叉"。
 */
trait CanExport
{
    protected bool $exportable = false;

    /**
     * 每批查询条数，避免大表一次性载入内存。
     */
    protected int $exportChunkSize = 1000;

    /**
     * 导出时可排除的列（如操作列、图片列）。
     *
     * @var array<int, string>
     */
    protected array $exportExcept = [];

    /**
     * 开启导出。
     *
     * 用法：$grid->export();
     */
    public function export(bool $value = true): static
    {
        $this->exportable = $value;

        return $this;
    }

    /**
     * 导出时排除某些列。
     *
     * @param  array<int, string>  $columns
     */
    public function exportExcept(array $columns): static
    {
        $this->exportExcept = $columns;

        return $this;
    }

    public function exportChunkSize(int $size): static
    {
        $this->exportChunkSize = max(100, $size);

        return $this;
    }

    public function isExportable(): bool
    {
        return $this->exportable;
    }

    public function getExportChunkSize(): int
    {
        return $this->exportChunkSize;
    }

    /**
     * @return array<int, string>
     */
    public function getExportExcept(): array
    {
        return $this->exportExcept;
    }

    /**
     * 导出用的列（排除指定列）。
     *
     * @return array<int, array{name: string, label: string}>
     */
    public function exportColumns(): array
    {
        $out = [];

        foreach ($this->columns as $name => $column) {
            if (in_array($name, $this->exportExcept, true)) {
                continue;
            }

            $node = $column->toNode();
            $out[] = ['name' => $node->name, 'label' => $node->label];
        }

        return $out;
    }
}
