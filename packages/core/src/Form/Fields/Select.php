<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

use Aimanong\Form\Concerns\ResolvesDictionary;

class Select extends Field
{
    use ResolvesDictionary;

    protected string $type = 'select';

    /**
     * 选项。接受数组或 PHP 枚举的 cases() 结果。
     *
     * @param  array<string, mixed>|array<int, mixed>  $options
     */
    /**
     * 声明选项来自一个枚举类。
     *
     * ## 为什么必须在这里补 options
     *
     * 基类 `Field::using()` 只设了 `props.enum` + `props.display = 'enum'`，
     * **从不填 `props.options`** —— 而前端表单是从 `props.options` 渲染
     * `<select>` 的。后果（实测）：
     *
     *     $form->select('status')->using(Status::class);
     *     → 下拉里只有「请选择…」，一个选项都没有
     *
     * 而同一个 `using()` 在**列表列**上是有用的（`Grid\Column::using()`），
     * 所以在表单上照搬它看起来完全合理。一个 agent 实测撞到。
     *
     * 修在 `toNode()` 里（而不是覆写 `using()`）是为了让
     * `using()` 与 `options()` 的**调用顺序无关**。
     */
    public function toNode(): \Aimanong\Schema\Ast\FieldNode
    {
        $enum = $this->props['enum'] ?? null;

        if (is_string($enum) && enum_exists($enum) && ($this->props['options'] ?? []) === []) {
            $normalized = [];

            foreach (\Aimanong\Support\EnumLabel::map($enum) as $value => $label) {
                $normalized[] = ['value' => $value, 'label' => $label];
            }

            $this->props['options'] = $normalized;
        }

        return parent::toNode();
    }

    /**
     * @param  array<int|string, mixed>  $options
     */
    public function options(array $options): static
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            /*
             * ⚠️ 枚举的标签要走 `EnumLabel`，**不能**写死 `$value->value`。
             *
             * 原先就是写死的，后果（实测）：按文档推荐的
             * `->options(Status::cases())` 写出来，下拉里显示的是
             * `active`/`disabled` 英文原值 —— 而文档承诺「值→标签映射」。
             * 一个 agent 为此做了对照实验（给枚举逐个加
             * getLabel/text/title/description/name 试），确认框架一个都不调用。
             *
             * 与列表列 `->using()` 是同一个 bug 的两面，所以标签规则只留一处。
             */
            if ($value instanceof \UnitEnum) {
                $normalized[] = [
                    'value' => \Aimanong\Support\EnumLabel::value($value),
                    'label' => \Aimanong\Support\EnumLabel::of($value),
                ];
            } elseif (is_string($value) || is_numeric($value)) {
                $normalized[] = ['value' => $key, 'label' => (string) $value];
            }
        }

        $this->props['options'] = $normalized;

        return $this;
    }

    public function multiple(bool $value = true): static
    {
        $this->props['multiple'] = $value;

        return $this;
    }
}
