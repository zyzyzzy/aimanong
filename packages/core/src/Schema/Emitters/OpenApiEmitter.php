<?php

declare(strict_types=1);

namespace Aimanong\Schema\Emitters;

use Aimanong\Aimanong;
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
    /**
     * 后台 URL —— 优先走 `Aimanong::url()`，没注册驱动时退回约定路径。
     *
     * 为什么要有兜底：本 emitter 在 core 里，而 core 是**零框架依赖**的。
     * 单测、离线编译产物等场景下可能没有注册 FrameworkRuntime，
     * 那时 `Aimanong::url()` 会抛 DriverNotRegisteredException ——
     * 一个「为了修文档路径」的改动不该让离线编译整个炸掉。
     */
    protected function path(string $suffix): string
    {
        try {
            return Aimanong::url($suffix);
        } catch (\Throwable) {
            return '/admin/'.ltrim($suffix, '/');
        }
    }

    /**
     * @return array{paths: array<string, array<string, mixed>>, components: array<string, mixed>}
     */
    public function emit(ResourceNode $node): array
    {
        /*
         * 只读资源**不能**声明写接口。
         *
         * ## 为什么这条很重要
         *
         * `Resource::readonly()` 在接口层会让 store/update/destroy 一律 403，
         * 但 OpenAPI 里若仍写着 POST / PUT / DELETE，AI 照文档写代码
         * 就会写出必然 403 的调用 —— 而且它没有任何理由怀疑文档。
         *
         * 这正是 `方案.md` 第一条要杜绝的事：
         * 「Schema 是唯一真相 …… 杜绝文档与实现漂移，这是 AI 出错的最大来源」。
         *
         * 回归：v1.6.0 引入只读资源时漏改了这里，
         * 四份产物里只有 schema.json 带 readonly 标志（见 EmittersTest）。
         */
        $collection = ['get' => $this->listOperation($node)];
        $item = ['get' => $this->detailOperation($node)];

        if (! $this->isReadonly($node)) {
            $collection['post'] = $this->createOperation($node);
            $item['put'] = $this->updateOperation($node);
            $item['delete'] = $this->deleteOperation($node);
        }

        /*
         * ⚠️ 路径必须走 `Aimanong::url()` —— 它是**唯一**构建后台 URL 的地方。
         *
         * 这里原先硬编码 `/admin/{uri}`，漏了中间那层 `/api`：
         *
         *     文档写的：  GET /admin/users        ← 其实是 SPA 的 HTML 页面
         *     真实路由：  GET /admin/api/users    ← 返回 JSON
         *
         * 后果（实测）：多个 AI agent 照文档 curl `/admin/users`，拿回一坨
         * HTML，只能靠 `php artisan route:list` 才发现真接口。
         * **文档与实现漂移，正是本项目第一条要杜绝的事** ——
         * 而且它骗的是最信任文档的那类调用方。
         *
         * 走 `Aimanong::url()` 顺带解决另一件事：自定义 URL 前缀的项目
         * （`urlPrefix()` 不是 `admin`）以前拿到的文档路径也是错的。
         */
        return [
            'paths' => [
                $this->path("api/{$node->uri}") => $collection,
                $this->path("api/{$node->uri}/{id}") => $item,
            ],
            'components' => [
                'schemas' => [
                    $this->schemaName($node) => $this->schema($node),
                ],
            ],
        ];
    }

    /**
     * 资源是否只读（前端隐藏写入口、接口 403）。
     */
    protected function isReadonly(ResourceNode $node): bool
    {
        return ($node->meta['readonly'] ?? false) === true;
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
