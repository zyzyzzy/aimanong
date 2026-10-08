<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

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
    protected function node()
    {
        return (new Compiler())->compile(Fixtures\ArticleResource::class);
    }

    public function test_json_schema_contains_all_fields(): void
    {
        $json = (new JsonSchemaEmitter())->emit($this->node());

        $this->assertSame('articles', $json['uri']);
        $this->assertCount(3, $json['grid']['columns']);
        $this->assertCount(3, $json['form']['fields']);

        $names = array_column($json['form']['fields'], 'name');
        $this->assertSame(['title', 'body', 'published'], $names);
    }

    public function test_json_schema_maps_types_correctly(): void
    {
        $json = (new JsonSchemaEmitter())->emit($this->node());

        $types = array_column($json['form']['fields'], 'type', 'name');

        $this->assertSame('text', $types['title']);
        $this->assertSame('textarea', $types['body']);
        $this->assertSame('switch', $types['published']);

        $jsonTypes = array_column($json['form']['fields'], 'jsonType', 'name');
        $this->assertSame('boolean', $jsonTypes['published']);
    }

    public function test_typescript_emits_interface(): void
    {
        $ts = (new TypeScriptEmitter())->emit($this->node());

        $this->assertStringContainsString('export interface Articles', $ts);
        $this->assertStringContainsString('title: string;', $ts);
        $this->assertStringContainsString('published?: boolean;', $ts);
    }

    public function test_openapi_has_crud_paths(): void
    {
        $oa = (new OpenApiEmitter())->emit($this->node());

        $this->assertArrayHasKey('/admin/articles', $oa['paths']);
        $this->assertArrayHasKey('/admin/articles/{id}', $oa['paths']);
        $this->assertArrayHasKey('Articles', $oa['components']['schemas']);
    }

    public function test_ai_prompt_is_human_readable(): void
    {
        $ai = (new AiPromptEmitter())->emit($this->node());

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

        $json = (new JsonSchemaEmitter())->emit($node);
        $ai = (new AiPromptEmitter())->emit($node);
        $oa = (new OpenApiEmitter())->emit($node);

        $jsonCount = count($json['form']['fields']);
        $oaCount = count($oa['components']['schemas']['Articles']['properties']);

        $this->assertSame($jsonCount, $oaCount, 'OpenAPI 与 JSON Schema 字段数不一致');
        $this->assertStringContainsString('`published`', $ai, 'AI 提示词缺失字段');
    }
}
