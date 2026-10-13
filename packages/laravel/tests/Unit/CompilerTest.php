<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Schema\Compiler;
use Aimanong\Support\FieldType;
use PHPUnit\Framework\TestCase;

class CompilerTest extends TestCase
{
    /** @return class-string<\Aimanong\Contracts\Resource> */
    protected function makeResource(): string
    {
        /** @var class-string<\Aimanong\Contracts\Resource> $class */
        $class = Fixtures\ArticleResource::class;

        return $class;
    }

    public function test_compile_produces_all_nodes(): void
    {
        $node = (new Compiler)->compile($this->makeResource());

        $this->assertSame('articles', $node->uri);
        $this->assertSame('文章', $node->label);
        $this->assertCount(3, $node->columns);
        $this->assertCount(3, $node->fields);
    }

    public function test_column_flags_are_compiled(): void
    {
        $node = (new Compiler)->compile($this->makeResource());

        $id = $node->columns[0];
        $this->assertTrue($id->sortable);
        $this->assertFalse($id->searchable);

        $title = $node->columns[1];
        $this->assertTrue($title->searchable);
    }

    public function test_validation_rules_are_collected(): void
    {
        $node = (new Compiler)->compile($this->makeResource());

        $rules = $node->meta['rules'];

        $this->assertContains('required', $rules['title']);
        $this->assertContains('max:255', $rules['title']);
    }

    public function test_field_type_suggestion(): void
    {
        $this->assertSame('text', FieldType::suggest('texte'));
        $this->assertSame('textarea', FieldType::suggest('taxtarea'));
        $this->assertSame('switch', FieldType::suggest('swich'));
        $this->assertNull(FieldType::suggest('zzzzzzzz'));
    }

    public function test_field_type_mapping(): void
    {
        $this->assertSame('string', FieldType::toJsonType('text'));
        $this->assertSame('boolean', FieldType::toJsonType('switch'));
        $this->assertSame('integer', FieldType::toJsonType('number'));
    }
}
