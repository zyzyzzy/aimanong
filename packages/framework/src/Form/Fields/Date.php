<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Date extends Field
{
    protected string $type = 'date';

    public function format(string $format): static
    {
        $this->props['format'] = $format;

        return $this;
    }
}
