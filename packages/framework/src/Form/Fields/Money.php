<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Money extends Field
{
    protected string $type = 'money';

    public function symbol(string $symbol): static
    {
        $this->props['symbol'] = $symbol;

        return $this;
    }

    public function decimals(int $places): static
    {
        $this->props['decimals'] = $places;

        return $this;
    }
}
