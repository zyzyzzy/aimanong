<?php

declare(strict_types=1);

namespace Aimanong\Show;

use Aimanong\Form\Fields\Field;
use Aimanong\Form\Fields\Text;
use Aimanong\Schema\Ast\FieldNode;

/**
 * 详情页定义。
 *
 * 复用 Field 类，但语义为只读展示。
 */
class Show
{
    /**
     * @var array<string, Field>
     */
    protected array $fields = [];

    public function field(string $name, ?string $label = null): Field
    {
        $field = new Text($name, $label);
        $field->readonly(true);

        $this->fields[$name] = $field;

        return $field;
    }

    /**
     * @param  array<int, string>  $names
     */
    public function fields(array $names): static
    {
        foreach ($names as $name) {
            $this->field($name);
        }

        return $this;
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fields' => array_map(fn (FieldNode $n): array => $n->toArray(), $this->toNodes()),
        ];
    }
}
