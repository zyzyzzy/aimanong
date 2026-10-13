<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Decimal extends Field
{
    protected string $type = 'decimal';

    public function decimals(int $places): static
    {
        $this->props['decimals'] = $places;

        return $this;
    }
}
