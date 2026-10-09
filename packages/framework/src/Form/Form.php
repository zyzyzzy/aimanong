<?php

declare(strict_types=1);

namespace Aimanong\Form;

use Aimanong\Form\Fields\Checkbox;
use Aimanong\Form\Fields\Color;
use Aimanong\Form\Fields\Date;
use Aimanong\Form\Fields\DateRange;
use Aimanong\Form\Fields\Datetime;
use Aimanong\Form\Fields\Decimal;
use Aimanong\Form\Fields\Display;
use Aimanong\Form\Fields\Divider;
use Aimanong\Form\Fields\Email;
use Aimanong\Form\Fields\Field;
use Aimanong\Form\Fields\Hidden;
use Aimanong\Form\Fields\Icon;
use Aimanong\Form\Fields\Money;
use Aimanong\Form\Fields\MultiSelect;
use Aimanong\Form\Fields\Number;
use Aimanong\Form\Fields\Password;
use Aimanong\Form\Fields\Radio;
use Aimanong\Form\Fields\Rate;
use Aimanong\Form\Fields\Select;
use Aimanong\Form\Fields\Slider;
use Aimanong\Form\Fields\SwitchField;
use Aimanong\Form\Fields\Tags;
use Aimanong\Form\Fields\Tel;
use Aimanong\Form\Fields\Text;
use Aimanong\Form\Fields\Textarea;
use Aimanong\Form\Fields\Time;
use Aimanong\Form\Fields\Url;
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

    public function password(string $name, ?string $label = null): Password
    {
        return $this->add(new Password($name, $label));
    }

    public function tel(string $name, ?string $label = null): Tel
    {
        return $this->add(new Tel($name, $label));
    }

    public function money(string $name, ?string $label = null): Money
    {
        return $this->add(new Money($name, $label));
    }

    public function rate(string $name, ?string $label = null): Rate
    {
        return $this->add(new Rate($name, $label));
    }

    public function slider(string $name, ?string $label = null): Slider
    {
        return $this->add(new Slider($name, $label));
    }

    public function multiSelect(string $name, ?string $label = null): MultiSelect
    {
        return $this->add(new MultiSelect($name, $label));
    }

    public function radio(string $name, ?string $label = null): Radio
    {
        return $this->add(new Radio($name, $label));
    }

    public function checkbox(string $name, ?string $label = null): Checkbox
    {
        return $this->add(new Checkbox($name, $label));
    }

    public function time(string $name, ?string $label = null): Time
    {
        return $this->add(new Time($name, $label));
    }

    public function dateRange(string $name, ?string $label = null): DateRange
    {
        return $this->add(new DateRange($name, $label));
    }

    public function color(string $name, ?string $label = null): Color
    {
        return $this->add(new Color($name, $label));
    }

    public function icon(string $name, ?string $label = null): Icon
    {
        return $this->add(new Icon($name, $label));
    }

    public function tags(string $name, ?string $label = null): Tags
    {
        return $this->add(new Tags($name, $label));
    }

    /**
     * 分隔线 / 分组标题（纯展示，不产生数据）。
     */
    public function divider(string $title = ''): Divider
    {
        return $this->add(new Divider($title));
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
