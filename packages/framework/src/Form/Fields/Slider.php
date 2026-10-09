<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Slider extends Field
{
    protected string $type = 'slider';

    public function range(int $min, int $max): static
    {
        $this->props['min'] = $min;
        $this->props['max'] = $max;

        return $this;
    }

    public function step(int $step): static
    {
        $this->props['step'] = $step;

        return $this;
    }
}
