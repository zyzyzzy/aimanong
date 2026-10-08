<?php

declare(strict_types=1);

namespace Aimanong\Form;

use Aimanong\Form\Fields\{
    Date, Datetime, Decimal, Display, Email, Field, Hidden, Number, Select, SwitchField, Text, Textarea, Url
};
use Aimanong\Schema\Ast\FieldNode;

/**
 * 表单定义。
 *
 * AI-First 约定：
 *   - 每个字段类型一个方法，签名唯一（仅接收字段名）
 *   - 禁止嵌套闭包
 *   - 全部配置链式完成
 */
class Form
{
    /**
     * @var array<string, Field>
     */
    protected array $fields = [];

    public function text(string $name, ?string $label = null): Text
    {
        return $this->add(new Text($name, $label));
    }

    public function textarea(string $name, ?string $label = null): Textarea
    {
        return $this->add(new Textarea($name, $label));
    }

    public function number(string $name, ?string $label = null): Number
    {
        return $this->add(new Number($name, $label));
    }

    public function decimal(string $name, ?string $label = null): Decimal
    {
        return $this->add(new Decimal($name, $label));
    }

    public function select(string $name, ?string $label = null): Select
    {
        return $this->add(new Select($name, $label));
    }

    public function switch(string $name, ?string $label = null): SwitchField
    {
        return $this->add(new SwitchField($name, $label));
    }

    public function date(string $name, ?string $label = null): Date
    {
        return $this->add(new Date($name, $label));
    }

    public function datetime(string $name, ?string $label = null): Datetime
    {
        return $this->add(new Datetime($name, $label));
    }

    public function email(string $name, ?string $label = null): Email
    {
        return $this->add(new Email($name, $label));
    }

    public function url(string $name, ?string $label = null): Url
    {
        return $this->add(new Url($name, $label));
    }

    public function hidden(string $name): Hidden
    {
        return $this->add(new Hidden($name));
    }

    public function display(string $name, ?string $label = null): Display
    {
        return $this->add(new Display($name, $label));
    }

    /**
     * @template T of Field
     *
     * @param  T  $field
     * @return T
     */
    protected function add(Field $field): Field
    {
        $this->fields[$field->getName()] = $field;

        return $field;
    }

    /**
     * @return array<int, FieldNode>
     */
    public function toNodes(): array
    {
        return array_map(
            fn (Field $f): FieldNode => $f->toNode(),
            array_values($this->fields)
        );
    }

    /**
     * @return array<int, string>
     */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }

    /**
     * 生成 Laravel 验证规则数组。
     *
     * @return array<string, array<int, string>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->fields as $name => $field) {
            $fieldRules = $field->toNode()->rules;

            if ($fieldRules !== []) {
                $rules[$name] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fields' => array_map(fn (FieldNode $n): array => $n->toArray(), $this->toNodes()),
            'rules' => $this->validationRules(),
        ];
    }
}
