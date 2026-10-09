<?php

declare(strict_types=1);

namespace Aimanong\Schema\Ast;

/**
 * Resource AST 根节点。
 *
 * 一个 ResourceNode 是一份声明编译后的完整中间表示，
 * 所有 Emitter 都从它产出各自格式的产物。
 */
class ResourceNode
{
    /**
     * @param  array<int, ColumnNode>  $columns
     * @param  array<int, FieldNode>  $fields
     * @param  array<int, FieldNode>  $detailFields
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $label,
        public readonly string $model,
        public readonly array $columns = [],
        public readonly array $fields = [],
        public readonly array $detailFields = [],
        public readonly array $meta = [],
    ) {}

    /**
     * @return array<int, ColumnNode>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<int, FieldNode>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'label' => $this->label,
            'model' => $this->model,
            'columns' => array_map(fn (ColumnNode $c): array => $c->toArray(), $this->columns),
            'fields' => array_map(fn (FieldNode $f): array => $f->toArray(), $this->fields),
            'detailFields' => array_map(fn (FieldNode $f): array => $f->toArray(), $this->detailFields),
        ];
    }
}
