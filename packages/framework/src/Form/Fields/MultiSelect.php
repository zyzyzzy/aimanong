<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

use Aimanong\Form\Concerns\ResolvesDictionary;

class MultiSelect extends Field
{
    use ResolvesDictionary;

    protected string $type = 'multiselect';

    /**
     * 绑定的多对多关联名（如 tags）。
     *
     * 声明后，Repository 写入时会自动 `sync()` 中间表 ——
     * 无需用户手写模型事件。
     */
    protected ?string $relation = null;

    /**
     * 声明本字段对应模型上的 **多对多关联**。
     *
     * 用法：$form->multiSelect('tags')->relation('tags')->options(...)
     *
     * 效果：
     *   1. 写入时自动 sync 中间表（主表保存后）
     *   2. 编辑时自动回填已选值
     *   3. 该字段不会作为普通列写入主表
     */
    public function relation(string $name): static
    {
        $this->relation = $name;
        $this->props['relation'] = $name;

        return $this;
    }

    public function getRelation(): ?string
    {
        return $this->relation;
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $options
     */
    public function options(array $options): static
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            if ($value instanceof \BackedEnum) {
                $normalized[] = ['value' => $value->value, 'label' => $value->value];
            } elseif ($value instanceof \UnitEnum) {
                $normalized[] = ['value' => $value->name, 'label' => $value->name];
            } elseif (is_string($value) || is_numeric($value)) {
                $normalized[] = ['value' => $key, 'label' => (string) $value];
            }
        }

        $this->props['options'] = $normalized;

        return $this;
    }
}
