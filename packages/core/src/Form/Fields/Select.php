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

    public function multiple(bool $value = true): static
    {
        $this->props['multiple'] = $value;

        return $this;
    }
}
