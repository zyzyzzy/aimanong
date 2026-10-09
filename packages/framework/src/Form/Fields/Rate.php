<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Rate extends Field
{
    protected string $type = 'rate';

    public function max(int $stars): static
    {
        $this->rules("max:{$stars}");
        $this->props['max'] = $stars;

        return $this;
    }

    public function allowHalf(bool $value = true): static
    {
        $this->props['allowHalf'] = $value;

        return $this;
    }
}
