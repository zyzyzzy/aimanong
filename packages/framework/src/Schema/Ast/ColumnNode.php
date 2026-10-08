<?php

declare(strict_types=1);

namespace Aimanong\Schema\Ast;

/**
 * 表格列 AST 节点。
 */
class ColumnNode
{
    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly bool $sortable = false,
        public readonly bool $searchable = false,
        public readonly bool $filterable = false,
        public readonly ?string $formatter = null,
        public readonly ?string $enumClass = null,
        public readonly array $props = [],
        public readonly array $meta = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'sortable' => $this->sortable,
            'searchable' => $this->searchable,
            'filterable' => $this->filterable,
            'formatter' => $this->formatter,
            'enum' => $this->enumClass,
            'props' => $this->props,
        ];
    }
}
