<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Datetime extends Field
{
    protected string $type = 'datetime';

    public function format(string $format): static
    {
        $this->props['format'] = $format;

        return $this;
    }
}
