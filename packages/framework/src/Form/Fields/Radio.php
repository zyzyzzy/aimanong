<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Radio extends Field
{
    protected string $type = 'radio';

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
