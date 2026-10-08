<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Capabilities;
use Aimanong\Support\FieldType;
use PHPUnit\Framework\TestCase;

/**
 * AI 能力层测试。
 *
 * 验证：框架能完整自省"我有什么能力"，且错误可自愈。
 */
class AiCapabilitiesTest extends TestCase
{
    public function test_field_type_catalog_is_complete(): void
    {
        $types = FieldType::all();

        $this->assertNotEmpty($types);
        $this->assertContains('text', $types);
        $this->assertContains('select', $types);
        $this->assertContains('switch', $types);
    }

    public function test_suggestion_uses_edit_distance(): void
    {
        // AI 常见拼写错误必须能纠正
        $this->assertSame('text', FieldType::suggest('texte'));
        $this->assertSame('textarea', FieldType::suggest('taxtarea'));
        $this->assertSame('switch', FieldType::suggest('swich'));
        $this->assertSame('select', FieldType::suggest('selct'));
        $this->assertSame('number', FieldType::suggest('numbr'));
    }

    public function test_suggestion_returns_null_when_far_off(): void
    {
        // 距离太远不应乱给建议
        $this->assertNull(FieldType::suggest('zzzzzzzz'));
        $this->assertNull(FieldType::suggest('completely_different'));
    }

    public function test_ai_readable_exception_exposes_structured_context(): void
    {
        try {
            new class('x') extends \Aimanong\Form\Fields\Field
            {
                protected string $type = 'texte';
            };
            $this->fail('应当抛出 UnknownFieldTypeException');
        } catch (\Aimanong\Exceptions\UnknownFieldTypeException $e) {
            $ctx = $e->context();

            $this->assertSame('UNKNOWN_FIELD_TYPE', $e::errorCode());
            $this->assertArrayHasKey('hint', $ctx);
            $this->assertArrayHasKey('example', $ctx);
            $this->assertArrayHasKey('docs', $ctx);
            $this->assertStringContainsString('text', $ctx['hint']);
        }
    }

    public function test_column_and_form_options_are_documented(): void
    {
        // Capabilities 不依赖容器，可直接实例化部分方法
        $ref = new \ReflectionClass(Capabilities::class);

        $this->assertTrue($ref->hasMethod('columnOptions'));
        $this->assertTrue($ref->hasMethod('formOptions'));
        $this->assertTrue($ref->hasMethod('fieldTypes'));
    }
}
