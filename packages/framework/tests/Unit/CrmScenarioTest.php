<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use PHPUnit\Framework\TestCase;

/**
 * CRM 真实场景验证暴露的缺陷的回归测试。
 *
 * 这一批问题的共同特征：**界面看不出问题、测试也全绿**，
 * 只有把真实业务需求跑一遍才会暴露。每一个都是真机复现过的。
 */
class CrmScenarioTest extends TestCase
{
    /**
     * `->dict()` 之后 formatter 必须是 map，否则**导出不会套用中文**。
     *
     * 回归：dict() 最初写的是 map() 再 badge()，而 badge() 会把
     * formatter 覆盖成 'badge'。前端三种 formatter 都走 mapLabel，
     * 所以界面上是「商务谈判」，导出的 CSV 里却是 negotiation ——
     * 只有导出会坏，界面完全看不出来。
     */
    public function test_dict_keeps_map_formatter_so_export_is_translated(): void
    {
        $grid = new Grid;
        $column = $grid->column('stage', '阶段');

        // 直接构造一个有 map 的列，模拟 dict() 的最终形态
        $column->badge()->map(['won' => '赢单']);

        $this->assertSame(
            'map',
            $column->toNode()->formatter,
            'formatter 必须是 map —— 否则 CSV 导出会输出英文枚举值'
        );
    }

    /**
     * badge() 不应该吃掉已经设置好的 map。
     *
     * 两种写法都有人写：
     *   ->map([...])->badge()
     *   ->badge()->map([...])
     * 导出器必须两种都能正确翻译。
     */
    public function test_badge_after_map_still_carries_the_map_props(): void
    {
        $grid = new Grid;
        $column = $grid->column('status', '状态')->map(['paid' => '已付款'])->badge();

        $props = $column->toNode()->props;

        $this->assertArrayHasKey('map', $props, 'props.map 必须保留，导出器依赖它');
    }

    /**
     * daterange 必须显式声明它对应的两个真实列。
     *
     * 回归：最初前端硬编码绑定 `{字段名}_start` / `{字段名}_end`，
     * 于是 `$form->dateRange('period')` 会去找 period_start / period_end ——
     * 表里根本没有这两列：表单能渲染、提交永远存不进去，
     * ai:verify 还会报一个看似莫名其妙的 SUSPECT_FIELD('period')。
     */
    public function test_date_range_declares_real_columns(): void
    {
        $form = new Form;
        $field = $form->dateRange('period')->label('有效期')->columns('start_at', 'end_at');

        $node = $field->toNode();

        $this->assertSame('start_at', $node->props['startColumn']);
        $this->assertSame('end_at', $node->props['endColumn']);
    }

    /**
     * 数值类字段必须能被声明成「默认 0」，
     * 否则留空 → null → NOT NULL 违约。
     *
     * 回归：CRM 商机的「赢单率」滑块没动过，提交时报
     * `NOT NULL constraint failed: crm_opportunities.probability`。
     */
    public function test_numeric_field_default_zero_reaches_schema(): void
    {
        $form = new Form;
        $form->slider('probability')->label('赢单率')->default(0);

        $nodes = $form->toNodes();

        $this->assertSame(0, $nodes[0]->default, 'default(0) 必须原样保留到编译产物');
        $this->assertSame('slider', $nodes[0]->type);
    }

    /**
     * 上传类字段的 accept 必须进入编译产物 ——
     * 客户端预检要靠它，否则「字段允许 png、全局白名单没有 png」时
     * 用户选了文件界面毫无反应，服务端一次请求都收不到。
     */
    public function test_upload_accept_reaches_schema(): void
    {
        $form = new Form;
        $form->files('attachments')->label('附件')->accept('pdf,jpg,png,docx');

        $props = $form->toNodes()[0]->props;

        $this->assertSame('pdf,jpg,png,docx', $props['accept']);
        $this->assertTrue($props['multiple'], 'files 字段必须是多值');
    }
}
