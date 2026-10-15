<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Contracts\Resource;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\AiPromptEmitter;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Aimanong\Schema\Emitters\OpenApiEmitter;
use Aimanong\Schema\Emitters\TypeScriptEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Emitter 产物测试。
 *
 * 验证：一份声明 → 四份产物，且四份产物内容一致（无漂移）。
 */
class EmittersTest extends TestCase
{
    protected function node(): ResourceNode
    {
        /** @var class-string<\Aimanong\Contracts\Resource> $class */
        $class = Fixtures\ArticleResource::class;

        return (new Compiler)->compile($class);
    }

    public function test_json_schema_contains_all_fields(): void
    {
        $json = (new JsonSchemaEmitter)->emit($this->node());

        $this->assertSame('articles', $json['uri']);
        $this->assertCount(3, $json['grid']['columns']);
        $this->assertCount(3, $json['form']['fields']);

        $names = array_column($json['form']['fields'], 'name');
        $this->assertSame(['title', 'body', 'published'], $names);
    }

    public function test_json_schema_maps_types_correctly(): void
    {
        $json = (new JsonSchemaEmitter)->emit($this->node());

        $types = array_column($json['form']['fields'], 'type', 'name');

        $this->assertSame('text', $types['title']);
        $this->assertSame('textarea', $types['body']);
        $this->assertSame('switch', $types['published']);

        $jsonTypes = array_column($json['form']['fields'], 'jsonType', 'name');
        $this->assertSame('boolean', $jsonTypes['published']);
    }

    public function test_typescript_emits_interface(): void
    {
        $ts = (new TypeScriptEmitter)->emit($this->node());

        $this->assertStringContainsString('export interface Articles', $ts);
        $this->assertStringContainsString('title: string;', $ts);
        $this->assertStringContainsString('published?: boolean;', $ts);
    }

    public function test_openapi_has_crud_paths(): void
    {
        $oa = (new OpenApiEmitter)->emit($this->node());

        /*
         * ⚠️ 路径必须带 `/api` —— 这两条断言原先写的是 `/admin/articles`，
         * **把 bug 锁进了测试里**：它们一直是绿的，而真实路由是
         * `/admin/api/articles`。
         *
         * 后果（实测）：多个 AI agent 照 openapi 文档 curl `/admin/articles`，
         * 拿回一坨 SPA 的 HTML，只能靠 `php artisan route:list` 才找到真接口。
         *
         * 断言里同时带上「不带 /api 的旧路径必须不存在」——
         * 否则将来有人改回硬编码，这些断言照样是绿的。
         */
        $this->assertArrayHasKey('/admin/api/articles', $oa['paths']);
        $this->assertArrayHasKey('/admin/api/articles/{id}', $oa['paths']);
        $this->assertArrayNotHasKey('/admin/articles', $oa['paths']);
        $this->assertArrayHasKey('Articles', $oa['components']['schemas']);
    }

    public function test_ai_prompt_is_human_readable(): void
    {
        $ai = (new AiPromptEmitter)->emit($this->node());

        $this->assertStringContainsString('文章', $ai);
        $this->assertStringContainsString('必填', $ai);
        $this->assertStringContainsString('`title`', $ai);
    }

    /**
     * 一致性：四份产物描述的是同一份声明。
     */
    public function test_all_emitters_agree_on_field_count(): void
    {
        $node = $this->node();

        $json = (new JsonSchemaEmitter)->emit($node);
        $ai = (new AiPromptEmitter)->emit($node);
        $oa = (new OpenApiEmitter)->emit($node);

        $jsonCount = count($json['form']['fields']);
        $oaCount = count($oa['components']['schemas']['Articles']['properties']);

        $this->assertSame($jsonCount, $oaCount, 'OpenAPI 与 JSON Schema 字段数不一致');
        $this->assertStringContainsString('`published`', $ai, 'AI 提示词缺失字段');
    }

    /**
     * 只读资源：四份产物必须**都**知道它是只读的。
     *
     * ## 这条断言为什么必要
     *
     * v1.6.0 引入 `Resource::readonly()` 时只改了 JsonSchemaEmitter，
     * 于是出现了一个非常危险的组合：
     *
     *   - 接口层：store/update/destroy 一律 403（正确）
     *   - OpenAPI：仍然宣告 POST / PUT / DELETE（错误）
     *   - AI 提示词：只字未提只读（错误）
     *
     * AI 照 OpenAPI 写代码 → 写出必然 403 的调用 → 而且它没有任何
     * 理由怀疑文档。这正是 `方案.md` 第一条要杜绝的「文档与实现漂移」。
     *
     * 断言「四份产物一致」比断言「某一份正确」更有价值 ——
     * 前者能防住**将来新增能力时又漏改一处**。
     */
    public function test_readonly_flag_reaches_all_emitters(): void
    {
        $node = (new Compiler)->compile(Fixtures\ReadonlyResource::class);

        $json = (new JsonSchemaEmitter)->emit($node);
        $oa = (new OpenApiEmitter)->emit($node);
        $ai = (new AiPromptEmitter)->emit($node);

        // 1. JSON Schema 带 readonly
        $this->assertTrue($json['readonly'], 'schema.json 未标记 readonly');

        // 2. OpenAPI **不得**出现写接口
        $collection = $oa['paths'][$this->apiPath($node)] ?? [];
        $item = $oa['paths'][$this->apiPath($node).'/{id}'] ?? [];

        $this->assertArrayHasKey('get', $collection);
        $this->assertArrayNotHasKey('post', $collection, '只读资源不该在 OpenAPI 里宣告 POST');
        $this->assertArrayHasKey('get', $item);
        $this->assertArrayNotHasKey('put', $item, '只读资源不该在 OpenAPI 里宣告 PUT');
        $this->assertArrayNotHasKey('delete', $item, '只读资源不该在 OpenAPI 里宣告 DELETE');

        // 3. AI 提示词明确告知只读
        $this->assertStringContainsString('只读资源', $ai, 'AI 提示词未告知只读，AI 会生成必然 403 的调用');
    }

    /**
     * 资源的数据 API 路径 —— **单点定义**。
     *
     * 以前这个前缀在 4 处各写一遍，且全都漏了 `/api` ——
     * 于是「4 条断言全绿，真实路由全错」。定义一次就不会再散落。
     */
    protected function apiPath(ResourceNode $node): string
    {
        return '/admin/api/'.$node->uri;
    }

    /**
     * 可写资源：OpenAPI 必须**有**写接口（防止上面那条修过头）。
     */
    public function test_writable_resource_keeps_write_endpoints(): void
    {
        $node = $this->node();

        $oa = (new OpenApiEmitter)->emit($node);

        $this->assertArrayHasKey('post', $oa['paths'][$this->apiPath($node)]);
        $this->assertArrayHasKey('put', $oa['paths'][$this->apiPath($node).'/{id}']);
        $this->assertArrayHasKey('delete', $oa['paths'][$this->apiPath($node).'/{id}']);
    }

    /**
     * 上传配置：四份产物都要认得。
     */
    public function test_upload_config_reaches_all_emitters(): void
    {
        $node = (new Compiler)->compile(Fixtures\OptionFormResource::class);

        $json = (new JsonSchemaEmitter)->emit($node);

        $this->assertArrayHasKey('upload', $json, 'schema.json 缺少 upload 配置');
        $this->assertArrayHasKey('urlTemplate', $json['upload']);
        $this->assertArrayHasKey('endpoint', $json['upload']);

        // types.ts 的接口签名必须声明这两个标志，否则 TS 消费者看不到
        $ts = (new TypeScriptEmitter)->emit($node);

        $this->assertStringContainsString('readonly: boolean', $ts);
        $this->assertStringContainsString('upload: AimanongUploadConfig', $ts);
    }

    /**
     * types.ts 引用的基类型必须有定义。
     *
     * 回归：`AimanongGridSchema` / `AimanongFormSchema` 曾经被引用
     * 但从未定义 —— 生成出来的 types.ts 直接编译不过，
     * 而「端到端类型安全」正是这份产物的存在理由。
     */
    /**
     * 基类型的字段名必须与 emitter 的**真实产出**对齐。
     *
     * 回归：第一版基类型是我按「合理的类型设计」写的，与真实产出
     * 有四处不符（缺 jsonType、tree 实际是 null、props/rules 空时是
     * 空数组而不是对象）—— 用真实 tsc 编译才暴露出来。
     *
     * 这条断言把「产出的键」与「基类型里声明的键」对齐，
     * 防止将来改了 emitter 而忘了改基类型。
     */
    public function test_base_types_match_emitted_shape(): void
    {
        $node = $this->node();

        $json = (new JsonSchemaEmitter)->emit($node);

        // 字段真实键
        $fieldKeys = array_keys($json['form']['fields'][0]);
        foreach ($fieldKeys as $key) {
            $this->assertStringContainsString(
                $key.':',
                (new TypeScriptEmitter)->header(),
                "AimanongField 缺少实际产出的键 {$key}"
            );
        }

        // 列真实键
        $columnKeys = array_keys($json['grid']['columns'][0]);
        foreach ($columnKeys as $key) {
            $this->assertStringContainsString(
                $key.':',
                (new TypeScriptEmitter)->header(),
                "AimanongColumn 缺少实际产出的键 {$key}"
            );
        }

        // 非树形资源的 tree 实际是 null
        $this->assertNull($json['grid']['tree']);
        $this->assertStringContainsString('null', (new TypeScriptEmitter)->header());
    }

    public function test_typescript_header_defines_referenced_base_types(): void
    {
        $header = (new TypeScriptEmitter)->header();

        foreach ([
            'AimanongGridSchema',
            'AimanongFormSchema',
            'AimanongField',
            'AimanongColumn',
            'AimanongUploadConfig',
        ] as $type) {
            $this->assertStringContainsString(
                "export interface {$type}",
                $header,
                "types.ts 引用了 {$type} 但没有定义它"
            );
        }
    }
}
