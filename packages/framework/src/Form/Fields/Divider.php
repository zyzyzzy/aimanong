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

        $this->hidden(true);
    }
}
