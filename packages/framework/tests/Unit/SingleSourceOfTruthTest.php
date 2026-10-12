<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Capabilities;
use Aimanong\Ai\RequirementChecker;
use Aimanong\Exceptions\GhostColumnException;
use Aimanong\Mcp\Tools\SearchDocs;
use Aimanong\Support\FieldType;
use Aimanong\Tree\Tree;
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
     * 回归（第五轮 AI 实测）：需求校验器必须支持 M5 新增能力。
     *
     * 此前喂入 {"tree":"zzz","export":"zzz"} 会静默返回
     * 「✅ 全部需求已满足」—— 未知键被忽略，与"需求达标度校验"定位冲突。
     */
    public function test_requirement_checker_supports_new_capabilities(): void
    {
        $checker = new RequirementChecker;
        $result = $checker->check(Fixtures\ArticleResource::class, [
            'tree' => true,
            'export' => true,
            'step' => true,
        ]);

        $this->assertTrue($result['checked']);
        $this->assertSame(3, $result['total'], 'tree/export/step 三个键都应被核对');
        // ArticleResource 没有这些能力，应全部不满足
        $this->assertFalse($result['all_satisfied']);
    }

    public function test_requirement_checker_rejects_unknown_key(): void
    {
        $checker = new RequirementChecker;
        $result = $checker->check(Fixtures\ArticleResource::class, [
            'bogus_key' => 'x',
        ]);

        $this->assertFalse(
            $result['all_satisfied'],
            '未知需求键必须报错，不能静默通过'
        );
        $this->assertStringContainsString('未知需求键', $result['results'][0]['requirement']);
    }

    /**
     * 回归（第五轮 + 宪法第三条）：draggable 不得在产物里**谎报可用**。
     *
     * 旧行为：`draggable()` → schema 写 `draggable: true`，
     *   同时写 `draggable_supported: false` —— **两个字段自相矛盾** ✗
     *   AI 读到 `draggable: true` 就会以为拖拽可用 ✗
     *
     * 新行为（宪法第三条 · 零隐式）：
     *   ① 默认 false（不谎报）
     *   ② `draggable_supported` 仍为 false（口径一致）
     *   ③ **不允许显式声明 true** —— 做不到就不能说能做
     */
    public function test_draggable_is_marked_unsupported(): void
    {
        $tree = new Tree;

        $arr = $tree->toArray();

        $this->assertFalse(
            $arr['draggable'],
            '默认不得谎报可拖拽（前端无拖拽 UI）'
        );
        $this->assertFalse(
            $arr['draggable_supported'],
            '拖拽 UI 尚未实现，必须明确标注，避免"声明了却以为能用"'
        );

        // ③ 显式声明 true = 声称一个做不到的事 → 必须报错
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/尚未实现/');
        $tree->draggable(true);
    }

    /**
     * 显式关闭拖拽（draggable(false)）应正常生效。
     */
    public function test_draggable_false_is_allowed(): void
    {
        $tree = new Tree;
        $tree->draggable(false);

        $this->assertFalse($tree->toArray()['draggable']);
        $this->assertFalse($tree->toArray()['draggable_supported']);
    }

    /**
     * search-docs 必须能用**业务语言**检索到能力。
     *
     * ## 回归（第六轮 AI 实测）
     *
     * 需求方说的是「运营希望以后自己改文案，不希望每次改文案都找开发」，
     * 而能力面里写的是「把枚举变成一等公民」—— 纯开发视角。
     * 「文案」「运营」在能力面出现 **0 次**，AI 拿业务词去查就是 MISS。
     *
     * 这不是分词问题，是**词表问题**：能力描述要能被
     * 「用业务语言提问的人」检索到。
     *
     * 修法是给能力加 `also_known_as` 业务同义词，
     * 并把检索从「整串匹配」改成「分词匹配」。
     */
    public function test_search_docs_matches_business_language(): void
    {
        $tool = new SearchDocs;

        foreach ([
            '运营希望自己改文案',   // → dict
            '处理留痕',             // → operation_log
            '现场照片要能传多张',   // → upload
            '事后能查到谁改的',     // → operation_log
        ] as $query) {
            $text = $tool->handle(new Request(['query' => $query]))
                ->content()->toArray()['text'] ?? '';

            $this->assertStringNotContainsString(
                '未找到',
                $text,
                "业务语言查询「{$query}」检索不到任何能力 —— 词表缺业务同义词"
            );
        }
    }

    /**
     * 反向：胡编的词仍然应该「未找到」，不能为了命中而命中。
     */
    public function test_search_docs_still_rejects_nonsense(): void
    {
        $text = (new SearchDocs)->handle(new Request(['query' => 'zzzqqqxxx不存在的东西']))
            ->content()->toArray()['text'] ?? '';

        $this->assertStringContainsString('未找到', $text);
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
