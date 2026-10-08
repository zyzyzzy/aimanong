<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Textarea extends Field
{
    protected string $type = 'textarea';

    public function rows(int $rows): static
    {
        $this->props['rows'] = $rows;

        return $this;
    }
}
