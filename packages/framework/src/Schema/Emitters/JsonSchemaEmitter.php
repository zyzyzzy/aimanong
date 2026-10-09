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
            'exportable' => $node->meta['exportable'] ?? false,
            // tree 为 null 表示非树形 Resource —— 前端据此切换渲染模式
            'tree' => $node->meta['tree'] ?? null,
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
        $fields = array_map(
            fn (FieldNode $f): array => $this->field($f),
            $node->fields
        );

        $steps = [];

        // 分步表单：把字段按步骤切分（字段定义已在编译期绑定到步骤）
        if (($node->meta['stepped'] ?? false) && ! empty($node->meta['steps'])) {
            $byName = [];
            foreach ($fields as $f) {
                $byName[$f['name']] = $f;
            }

            foreach ($node->meta['steps'] as $step) {
                $stepFields = [];

                foreach ($step['fields'] ?? [] as $sf) {
                    $name = is_array($sf) ? ($sf['name'] ?? null) : null;

                    if ($name !== null && isset($byName[$name])) {
                        $stepFields[] = $byName[$name];
                    }
                }

                $steps[] = ['title' => $step['title'] ?? '', 'fields' => $stepFields];
            }
        }

        return [
            'fields' => $fields,
            'rules' => $node->meta['rules'] ?? [],
            'stepped' => $node->meta['stepped'] ?? false,
            'steps' => $steps,
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
