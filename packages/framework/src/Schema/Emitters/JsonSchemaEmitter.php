<?php

declare(strict_types=1);

namespace Aimanong\Schema\Emitters;

use Aimanong\Schema\Ast\FieldNode;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Support\FieldType;

/**
 * 产出前端运行时 JSON Schema。
 *
 * 前端 Vue 组件直接消费此产物渲染页面，
 * 因此前端无需硬编码任何字段信息。
 */
class JsonSchemaEmitter
{
    /**
     * @return array<string, mixed>
     */
    public function emit(ResourceNode $node): array
    {
        return [
            '$schema' => 'https://aimanong.com/schema/v1.json',
            'uri' => $node->uri,
            'label' => $node->label,
            'model' => $node->model,
            'grid' => $this->grid($node),
            'form' => $this->form($node),
            'show' => $this->show($node),
            'generatedAt' => null, // 快照测试时保持确定性
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function grid(ResourceNode $node): array
    {
        return [
            'perPage' => $node->meta['perPage'] ?? 20,
            'columns' => array_map(
                fn ($c): array => $c->toArray(),
                $node->columns
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function form(ResourceNode $node): array
    {
        return [
            'fields' => array_map(
                fn (FieldNode $f): array => $this->field($f),
                $node->fields
            ),
            'rules' => $node->meta['rules'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function show(ResourceNode $node): array
    {
        return [
            'fields' => array_map(
                fn (FieldNode $f): array => $this->field($f),
                $node->detailFields
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function field(FieldNode $f): array
    {
        return [
            'name' => $f->name,
            'type' => $f->type,
            'label' => $f->label,
            'jsonType' => FieldType::toJsonType($f->type),
            'required' => $f->required,
            'readonly' => $f->readonly,
            'hidden' => $f->hidden,
            'default' => $f->default,
            'rules' => $f->rules,
            'props' => $f->props,
        ];
    }
}
