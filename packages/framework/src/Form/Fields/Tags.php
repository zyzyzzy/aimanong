<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Tags extends Field
{
    protected string $type = 'tags';

    public function separator(string $sep): static
    {
        $this->props['separator'] = $sep;

        return $this;
    }
}
