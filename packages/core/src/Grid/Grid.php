<?php

declare(strict_types=1);

namespace Aimanong\Grid;

use Aimanong\Grid\Concerns\CanExport;
use Aimanong\Schema\Ast\ColumnNode;

/**
 * 列表页定义。
 *
 * Aimanong 约定：所有配置走链式调用，禁止嵌套闭包。
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
    /**
     * 内置批量动作。
     *
     * @var array<int, string>
     */
    public const BATCH_ACTIONS = ['delete', 'export'];

    /** @var array<int, mixed> */
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
     * 声明批量操作。
     *
     * ```php
     * $grid->batchActions(['delete', 'export']);
     * ```
     *
     * ## 支持的动作
     *
     * | 动作 | 说明 |
     * |---|---|
     * | `delete` | 批量删除选中项（复用 destroy 权限） |
     * | `export` | 导出选中项（复用 export 权限；需先 `$grid->export()`） |
     *
     * ## 为什么只支持这两个
     *
     * 自定义批量动作需要「一段可执行的业务逻辑」，而框架的声明层
     * 刻意不接收闭包（AI 生成嵌套闭包错误率高，见 ADR-003）。
     * 所以这里只提供**内置**动作；未声明的动作名会在编译期报错，
     * 而不是静默忽略 —— 「声明了却不能用」是本项目最反对的情况。
     *
     * @param  array<int, string>  $actions
     */
    public function batchActions(array $actions): static
    {
        $unsupported = array_diff($actions, self::BATCH_ACTIONS);

        if ($unsupported !== []) {
            throw new \InvalidArgumentException(sprintf(
                '不支持的批量动作 [%s]。当前仅支持：%s。'
                .'（自定义批量动作需可执行逻辑，而声明层刻意不接收闭包，见 ADR-003）',
                implode(', ', $unsupported),
                implode(', ', self::BATCH_ACTIONS)
            ));
        }

        $this->batchActions = array_values($actions);

        return $this;
    }

    /**
     * 已声明的批量动作。
     *
     * @return array<int, string>
     */
    public function getBatchActions(): array
    {
        return $this->batchActions;
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
