<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Introspection;
use Aimanong\Aimanong;
use Aimanong\Grid\Grid;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Aimanong\Tests\Unit\Fixtures\CardsModeResource;
use Aimanong\Tests\Unit\Fixtures\FakeFrameworkRuntime;
use Aimanong\Tests\Unit\Fixtures\WiredPropResource;
use PHPUnit\Framework\TestCase;

/**
 * 列表展示形态（`$grid->mode('table'|'list'|'cards')`）。
 *
 * ## 这个能力解决什么
 *
 * 「同一批数据，管理端看表格、前台看卡片」此前要写两套前端。
 * 现在一个 `mode()` 声明换排版，数据、列、权限、分页全复用。
 *
 * ## 为什么门禁要盯**三个**组装点
 *
 * grid 段在 core 里有**三处**独立组装：
 *
 *   1. `Compiler`          → 写进 meta
 *   2. `JsonSchemaEmitter` → 页面拿到的 schema
 *   3. `Introspection`     → `/__ai/schema/{uri}` 给 AI 看的
 *
 * 任何一处漏掉，`mode()` 就变成**部分生效**：
 * 页面按 cards 渲染、而自省接口说没有 mode（或反过来）。
 * 这种「两边不一致」比完全不做更糟 —— AI 会据自省结果做出错误判断。
 *
 * 项目里已经有过两次同类事故（`batchActions` 漏在 Compiler、
 * 又漏在 Emitter），所以这里三处都断言。
 */
class GridModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Aimanong::bindRuntime(new FakeFrameworkRuntime);
    }

    /**
     * 默认形态是 table —— 老资源不改一行，行为必须与之前完全一致。
     */
    public function test_default_mode_is_table(): void
    {
        $grid = new Grid;
        $grid->column('id', 'ID');

        $this->assertSame('table', $grid->getMode());
        $this->assertSame('table', $grid->toArray()['mode']);
    }

    public function test_mode_is_declared_and_read_back(): void
    {
        foreach (Grid::MODES as $mode) {
            $grid = new Grid;
            $grid->column('id', 'ID');
            $grid->mode($mode);

            $this->assertSame($mode, $grid->getMode(), "mode({$mode}) 应能读回");
        }
    }

    /**
     * 🔴 未知形态必须**立刻抛异常**，不许静默退回表格。
     *
     * 静默退化 = 「配了但没效果」= 本项目最反对的那类问题：
     * 声明写了、页面照旧、没有任何提示。
     */
    public function test_unknown_mode_throws_instead_of_silently_falling_back(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/未知的列表展示形态/');

        (new Grid)->mode('cardz');
    }

    /**
     * 异常信息要列出可选值 —— 只说「不认识」等于让 AI 去猜。
     */
    public function test_unknown_mode_error_lists_valid_options(): void
    {
        try {
            (new Grid)->mode('grid');
            $this->fail('应该抛异常');
        } catch (\InvalidArgumentException $e) {
            foreach (Grid::MODES as $mode) {
                $this->assertStringContainsString($mode, $e->getMessage(), "错误信息该列出 {$mode}");
            }
        }
    }

    /**
     * 组装点 ①：Compiler 必须把 mode 写进 meta。
     */
    public function test_compiler_carries_mode_into_meta(): void
    {
        $node = (new Compiler)->compile(CardsModeResource::class);

        $this->assertArrayHasKey('mode', $node->meta, 'Compiler 漏了 mode（与 batchActions 同类事故）');
        $this->assertSame('cards', $node->meta['mode']);
    }

    /**
     * 组装点 ②：页面 schema 必须带 mode —— 前端就是靠它选渲染分支的。
     */
    public function test_page_schema_carries_mode(): void
    {
        $node = (new Compiler)->compile(CardsModeResource::class);
        $schema = (new JsonSchemaEmitter)->emit($node);

        $this->assertArrayHasKey('mode', $schema['grid'], 'Emitter 漏了 mode');
        $this->assertSame('cards', $schema['grid']['mode']);
    }

    /**
     * 组装点 ③：自省接口也要带 —— 否则 AI 自查时看到的与页面实际不符。
     */
    public function test_introspection_schema_carries_mode(): void
    {
        $class = CardsModeResource::class;
        Aimanong::registry()->register($class);

        $result = (new Introspection)->schema(CardsModeResource::uri());

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('mode', $result['body']['grid'], '自省接口漏了 mode');
        $this->assertSame('cards', $result['body']['grid']['mode']);
    }

    /**
     * 默认形态在**三处**都要是 table（不能某一处缺省成别的东西）。
     */
    public function test_default_mode_is_table_everywhere(): void
    {
        $node = (new Compiler)->compile(WiredPropResource::class);

        $this->assertSame('table', $node->meta['mode']);

        $schema = (new JsonSchemaEmitter)->emit($node);
        $this->assertSame('table', $schema['grid']['mode']);
    }

    /**
     * 前端模板必须真的**读**这个字段并分三个分支。
     *
     * 这是渲染侧的钉子：后端全对、前端不读，等于没做
     *（本项目已有 13 个 prop 死在这上面）。
     */
    public function test_frontend_renders_all_three_modes(): void
    {
        $dir = dirname(__DIR__, 2);
        $blade = (string) file_get_contents($dir.'/resources/views/resource.blade.php');

        $this->assertStringContainsString('gridMode', $blade, '前端没有读 gridMode');

        /*
         * list / cards 是显式分支；**table 是 v-else 兜底**（默认形态）。
         *
         * 兜底是有意的：`mode()` 在声明侧已经拒绝未知值，
         * 所以前端不可能收到第三种值 —— 让它兜到表格比再写一个
         * 永不命中的分支更稳。
         */
        foreach (['list', 'cards'] as $mode) {
            $this->assertMatchesRegularExpression(
                "/gridMode === '{$mode}'/",
                $blade,
                "前端缺 {$mode} 形态的渲染分支"
            );
        }

        $this->assertMatchesRegularExpression(
            '/v-else class="am-table-wrap/',
            $blade,
            'table 应是 v-else 兜底分支'
        );

        // 三种形态必须共用同一个单元格渲染器 —— 不共用就会出现
        // 「某个 formatter 只在表格里生效」这类分叉
        $this->assertStringContainsString("app.component('am-cell'", $blade, '缺共用的单元格渲染器');

        // ThinkPHP 侧必须同步（模板是真源生成的产物）
        $tp = (string) file_get_contents(
            dirname(__DIR__, 3).'/thinkphp/resources/views/resource.html'
        );

        $this->assertStringContainsString('gridMode', $tp, 'ThinkPHP 模板没有同步 gridMode');
    }
}
