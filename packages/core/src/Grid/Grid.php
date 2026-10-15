<?php

declare(strict_types=1);

namespace Aimanong\Grid;

use Aimanong\Grid\Actions\BatchAction;
use Aimanong\Support\SuggestsMethods;
use Aimanong\Grid\Concerns\CanExport;
use Aimanong\Schema\Ast\ColumnNode;

/**
 * 列表页定义。
 *
 * Aimanong 约定：所有配置走链式调用，禁止嵌套闭包。
 */
class Grid
{
    use SuggestsMethods;

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

    /** @var array<string, BatchAction> */
    protected array $customBatchActions = [];

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
     * 声明一个**自定义**批量动作。
     *
     * ```php
     * $grid->batchAction('ship', '标记为已发货')->handler(ShipOrders::class);
     * ```
     *
     * ## 为什么是「引用类」而不是「传闭包」
     *
     * ADR-003 定的是「声明层只放数据，不放行为」—— 因为声明要被序列化、
     * 自省、被 AI 读、被跨框架执行，闭包这四件都做不到。
     *
     * 但「不收闭包」不等于「不能有自定义动作」：中间那条路是**引用**。
     * 声明里放类名（数据），框架按名实例化并调用 ——
     * 既守住 ADR-003，又让动作真的可声明。
     *
     * 在本方法出现之前，`batchActions()` 是硬白名单 {delete, export}：
     * 实测中一个 LLM 为做「批量改状态」，把 23 个可能的动作名逐个塞进去
     * 全部被拒，最后自己写了一个扩展 + 注入 JS，并**丢掉了权限与字段校验**
     * （它自己在报告里标了这是安全缺口）。
     */
    public function batchAction(string $name, string $label): BatchAction
    {
        if (in_array($name, self::BATCH_ACTIONS, true)) {
            throw new \InvalidArgumentException(
                "批量动作 [{$name}] 是内置动作，不要用 batchAction() 重定义。"
                .'用 batchActions([...]) 声明即可。'
            );
        }

        $action = new BatchAction($name, $label);
        $this->customBatchActions[$name] = $action;

        return $action;
    }

    /**
     * 已声明的批量动作。
     *
     * @return array<int, string>
     */
    public function getBatchActions(): array
    {
        // 内置动作 + 自定义动作名，前端按同一个列表渲染按钮
        return array_merge($this->batchActions, array_keys($this->customBatchActions));
    }

    /**
     * 自定义批量动作的完整声明（含处理器类名）。
     *
     * 编译产物里带这个，控制器才能把动作名分发到处理器。
     *
     * @return array<int, array{name: string, label: string, handler: string}>
     */
    public function getCustomBatchActions(): array
    {
        $out = [];

        foreach ($this->customBatchActions as $action) {
            /*
             * 声明了动作却没给处理器 —— 编译期就拦住。
             * 否则运行期表现为「点按钮 501」，与「声明了却不能用」
             * （本项目最反对的情况）是同一个问题。
             */
            if ($action->getHandler() === null) {
                throw new \InvalidArgumentException(
                    "自定义批量动作 [{$action->getName()}] 没有指定处理器。"
                    ."用 ->handler(SomeHandler::class) 指定 —— 它需要实现 "
                    .\Aimanong\Contracts\BatchActionHandler::class
                );
            }

            $out[] = [
                'name' => $action->getName(),
                'label' => $action->getLabel(),
                'handler' => $action->getHandler(),
            ];
        }

        return $out;
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
