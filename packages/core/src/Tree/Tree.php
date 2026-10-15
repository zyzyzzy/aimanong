<?php

declare(strict_types=1);

namespace Aimanong\Tree;

use Aimanong\Support\SuggestsMethods;
/**
 * 树形结构定义。
 *
 * 用于无限极分类、部门层级、菜单等场景。
 *
 * Aimanong 约定：链式扁平、无重载、无闭包。
 */
class Tree
{
    use SuggestsMethods;

    protected string $parentColumn = 'parent_id';

    protected string $orderColumn = 'sort';

    protected string $titleColumn = 'name';

    /*
     * ⚠️ 默认值曾是 **true** ✗（违反宪法第三条 · 零隐式）
     *
     * 后果：schema.json / types.ts 里每个树形资源都写着 `draggable: true` ✗
     *   —— 而**前端根本没有拖拽交互** ✗（模板里 draggable 出现 0 次）
     *   → AI 读到 schema 就会以为「拖拽可用」，写出点不动的代码 ✗✗
     *
     * 更糟的是：同一份 schema 里还写着 `draggable_supported: false`，
     *   两个字段**自相矛盾** ✓→✗ AI 不知道该信哪个。
     *
     * 现在默认 false ✓：与 `draggable_supported: false` 口径一致，
     *   AI 读到的是「拖拽不可用」这个**真话** ✓。
     * 等拖拽 UI 真正实现后，再把这里改回 true 并同步 `draggable_supported`。
     */
    protected bool $draggable = false;

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
     *
     * ⚠️ **尚未实现**：前端目前没有拖拽交互 ✗
     *
     * 因此本方法**不接受 true** —— 传 true 会抛异常并说清原因。
     * 这不是限制，而是宪法第三条的要求：
     * 既然界面上做不到，schema 里就不能写「可以」。
     *
     * 需要调整层级时，请通过编辑表单的父级字段，
     * 或调用 PUT /admin/api/{uri}/{id}/move 接口（后端**已可用** ✓）。
     *
     * 等拖拽 UI 实现后，本方法恢复接受 true。
     */
    public function draggable(bool $value = true): static
    {
        if ($value === true) {
            throw new \InvalidArgumentException(
                '拖拽排序（draggable）尚未实现：当前前端没有拖拽 UI，'
                .'声明 true 会让 schema 产出不可用的能力。'
                .'调整层级请用编辑表单的父级字段，或 PUT /admin/api/{uri}/{id}/move 接口。'
            );
        }

        $this->draggable = false;

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
            // 明确告知调用方：拖拽 UI 尚未实现，避免"声明了却以为能用"
            'draggable_supported' => false,
            'maxDepth' => $this->maxDepth,
        ];
    }
}
