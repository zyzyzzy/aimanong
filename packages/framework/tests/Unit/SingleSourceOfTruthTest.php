<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Capabilities;
use Aimanong\Exceptions\GhostColumnException;
use Aimanong\Mcp\Tools\SearchDocs;
use Aimanong\Support\FieldType;
use Laravel\Mcp\Request;
use PHPUnit\Framework\TestCase;

/**
 * 第四轮 AI 实测暴露的缺陷的回归测试。
 *
 * 核心问题：**数据源分叉** —— 同一份信息有多个来源，
 * 只更新其中一个会导致 AI 拿到过时数据。
 */
class SingleSourceOfTruthTest extends TestCase
{
    /**
     * 回归：M4 新增展示器时只改了 Capabilities，
     * search-docs 仍是旧的硬编码列表，导致 AI 找不到 bool()/money()。
     */
    public function test_search_docs_includes_all_column_options(): void
    {
        $tool = new SearchDocs;
        $response = $tool->handle(new Request([]));
        $text = $response->content()->toArray()['text'] ?? '';

        // Capabilities 里有的，search-docs 必须也有
        foreach (array_keys((new Capabilities)->columnOptions()) as $option) {
            $this->assertStringContainsString(
                $option.'()',
                $text,
                "search-docs 缺少列选项 {$option}() —— 与 capabilities.json 数据分叉"
            );
        }
    }

    public function test_search_docs_includes_all_form_options(): void
    {
        $tool = new SearchDocs;
        $text = $tool->handle(new Request([]))->content()->toArray()['text'] ?? '';

        foreach (array_keys((new Capabilities)->formOptions()) as $option) {
            $this->assertStringContainsString(
                $option.'()',
                $text,
                "search-docs 缺少表单选项 {$option}()"
            );
        }
    }

    /**
     * 回归：perPage 此前在任何文档里都不存在，
     * AI 只能靠抄参考实现 —— 文档站必须覆盖它。
     */
    public function test_per_page_is_documented(): void
    {
        $options = (new Capabilities)->columnOptions();

        $this->assertArrayHasKey('perPage', $options, 'perPage 必须在列选项文档中');
        $this->assertStringContainsString('$grid->perPage', $options['perPage']);
    }

    /**
     * 回归：GhostColumnException 本身带 did_you_mean，
     * 但 Verifier 捕获后只取 message，丢失了可自愈上下文。
     */
    public function test_ghost_column_exception_carries_suggestion(): void
    {
        $e = new GhostColumnException(
            '声明了不存在的列: column:teacher_emial',
            ['column:teacher_emial'],
            ['id', 'title', 'teacher_email']
        );

        $ctx = $e->toArray();

        $this->assertSame('GHOST_COLUMN', $e::errorCode());
        $this->assertSame('teacher_email', $ctx['did_you_mean']);
        $this->assertArrayHasKey('available_columns', $ctx);
        $this->assertStringContainsString('teacher_email', $ctx['example']);
    }

    /**
     * 回归：M5 新增 export()/tree() 时，必须同步到 search-docs，
     * 否则又是第四轮实测发现过的"数据源分叉"。
     */
    public function test_search_docs_includes_export_and_tree_options(): void
    {
        $text = (new SearchDocs)->handle(new Request([]))->content()->toArray()['text'] ?? '';

        foreach (['export', 'exportExcept', 'exportChunkSize'] as $k) {
            $this->assertStringContainsString($k, $text, "search-docs 缺少导出选项 {$k}");
        }

        $this->assertStringContainsString('树形结构', $text, 'search-docs 缺少树形章节');
        $this->assertStringContainsString('parentColumn', $text);
    }

    public function test_capabilities_exposes_tree_options(): void
    {
        // 注意：toArray() 依赖容器（读 app()->version()），
        // 单元测试环境无容器，故直接断言方法本身。
        $opts = (new Capabilities)->treeOptions();

        $this->assertArrayHasKey('parentColumn', $opts);
        $this->assertArrayHasKey('titleColumn', $opts);
        $this->assertArrayHasKey('draggable', $opts);
    }

    public function test_capabilities_exposes_export_options(): void
    {
        $opts = (new Capabilities)->columnOptions();

        $this->assertArrayHasKey('export', $opts);
        $this->assertArrayHasKey('exportExcept', $opts);
    }

    /**
     * 字段类型数量必须与文档一致。
     */
    public function test_field_type_count_is_stable(): void
    {
        $types = FieldType::all();
        $caps = (new Capabilities)->fieldTypes();

        $this->assertCount(
            count($types),
            $caps,
            'Capabilities 的字段类型数必须与 FieldType::all() 一致'
        );
    }
}
