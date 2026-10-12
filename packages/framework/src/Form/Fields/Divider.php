<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

/**
 * 分隔线 / 分组标题（纯展示元素，不产生表单数据）。
 */
class Divider extends Field
{
    protected string $type = 'divider';

    public function __construct(string $title = '', ?string $label = null)
    {
        parent::__construct($title === '' ? '_divider_'.uniqid() : $title, $label);

        /*
         * ⚠️ 这里**曾经**调用了 `$this->hidden(true)` ✗
         *
         * 后果（违反宪法第三条 · 零隐式）：
         *   `visibleFields` = `fields.filter(f => !f.hidden)` ✓
         *   → 被隐藏的字段**根本不渲染** ✗
         *   而 `$form->divider('分组标题')` 在文档里是有的
         *   （`DocsCommand` 写着 `$form->divider('分组标题');` ✓）
         *   → **声明了、文档写了，界面上一个字都没有** ✗✗ 幽灵能力
         *
         * 分隔线本就是「纯展示元素」，不该走 hidden 这条路。
         * 现在：不隐藏 ✓ + 前端 `f.type === 'divider'` 有独立分支渲染 ✓
         */
    }
}
