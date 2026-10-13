<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Number extends Field
{
    protected string $type = 'number';

    public function step(int|float $step): static
    {
        $this->props['step'] = $step;

        return $this;
    }

    public function range(int|float $min, int|float $max): static
    {
        $this->props['min'] = $min;
        $this->props['max'] = $max;

        return $this->rules(['numeric', "between:{$min},{$max}"]);
    }
}
