<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class SwitchField extends Field
{
    protected string $type = 'switch';

    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);

        $this->default(false);
    }
}
