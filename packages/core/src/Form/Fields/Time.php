<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Time extends Field
{
    protected string $type = 'time';

    public function format(string $format): static
    {
        $this->props['format'] = $format;

        return $this;
    }
}
