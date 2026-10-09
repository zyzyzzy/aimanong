<?php

declare(strict_types=1);

namespace Aimanong\Schema\Emitters;

use Aimanong\Schema\Ast\FieldNode;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Support\FieldType;

/**
 * 产出 OpenAPI 3.1 文档片段。
 *
 * 使 REST API 文档与声明永远一致。
 */
class OpenApiEmitter
{
    /**
     * @return array<string, mixed>
     */
    public function emit(ResourceNode $node): array
    {
        return [
            'paths' => [
                "/admin/{$node->uri}" => [
                    'get' => $this->listOperation($node),
                    'post' => $this->createOperation($node),
                ],
                "/admin/{$node->uri}/{id}" => [
                    'get' => $this->detailOperation($node),
                    'put' => $this->updateOperation($node),
                    'delete' => $this->deleteOperation($node),
                ],
            ],
            'components' => [
                'schemas' => [
                    $this->schemaName($node) => $this->schema($node),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function listOperation(ResourceNode $node): array
    {
        return [
            'summary' => "{$node->label}列表",
            'tags' => [$node->label],
            'parameters' => [
                [
                    'name' => 'page',
                    'in' => 'query',
                    'schema' => ['type' => 'integer'],
                    'description' => '页码，从 1 开始',
                ],
                [
                    'name' => 'per_page',
                    'in' => 'query',
                    'schema' => ['type' => 'integer'],
                    'description' => '每页条数。不传则使用 Resource 中声明的 perPage',
                ],
                [
                    'name' => 'keyword',
                    'in' => 'query',
                    'schema' => ['type' => 'string'],
                    'description' => '快捷搜索关键词，在可搜索列上做模糊匹配',
                ],
                [
                    'name' => 'sort',
                    'in' => 'query',
                    'schema' => [
                        'type' => 'string',
                        'enum' => array_values(array_map(
                            fn ($c): string => $c->name,
                            array_filter($node->columns, fn ($c): bool => $c->sortable)
                        )),
                    ],
                    'description' => '排序字段，必须是可排序列之一',
                ],
                [
                    'name' => 'direction',
                    'in' => 'query',
                    'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
                    'description' => '排序方向。注意参数名是 direction，不是 order',
                ],
            ],
            'responses' => [
                '200' => ['description' => '成功'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function createOperation(ResourceNode $node): array
    {
        return [
            'summary' => "创建{$node->label}",
            'tags' => [$node->label],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/{$this->schemaName($node)}"],
                    ],
                ],
            ],
            'responses' => [
                '201' => ['description' => '创建成功'],
                '422' => ['description' => '验证失败'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detailOperation(ResourceNode $node): array
    {
        return [
            'summary' => "{$node->label}详情",
            'tags' => [$node->label],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ],
            'responses' => ['200' => ['description' => '成功']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateOperation(ResourceNode $node): array
    {
        return [
            'summary' => "更新{$node->label}",
            'tags' => [$node->label],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ],
            'responses' => [
                '200' => ['description' => '更新成功'],
                '422' => ['description' => '验证失败'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteOperation(ResourceNode $node): array
    {
        return [
            'summary' => "删除{$node->label}",
            'tags' => [$node->label],
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ],
            'responses' => ['204' => ['description' => '删除成功']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(ResourceNode $node): array
    {
        $properties = [];
        $required = [];

        foreach ($node->fields as $field) {
            $properties[$field->name] = $this->property($field);

            if ($field->required) {
                $required[] = $field->name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function property(FieldNode $field): array
    {
        $schema = ['type' => FieldType::toJsonType($field->type)];

        if ($field->props['options'] ?? null) {
            $schema['enum'] = array_column($field->props['options'], 'value');
        }

        if ($field->type === 'email') {
            $schema['format'] = 'email';
        }

        if ($field->type === 'url') {
            $schema['format'] = 'uri';
        }

        return $schema;
    }

    protected function schemaName(ResourceNode $node): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $node->uri)));
    }
}
