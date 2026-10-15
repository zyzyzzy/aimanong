<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Form\Fields\Select;
use Aimanong\Form\Fields\Text;
use Aimanong\Grid\Column;
use Aimanong\Support\EnumLabel;
use PHPUnit\Framework\TestCase;

enum LabelledStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return $this === self::Active ? '启用' : '禁用';
    }
}

enum GetLabelledStatus: string
{
    case On = 'on';

    public function getLabel(): string
    {
        return '开启';
    }
}

enum BareStatus: string
{
    case Active = 'active';
}

/**
 * 枚举的标签必须在**三条路径**上都到得了前端。
 *
 * ## 事故：同一个 bug 出现了三次
 *
 * 每次都是「声明合法 ✅ `ai:verify` 通过 ✅ 页面就是不对 ❌」：
 *
 *   ① 列表列 `->using(Enum::class)` —— 只写 `enum: 类名`，前端解析不了 PHP 类名
 *   ② 表单 `->options(Enum::cases())` —— 写死 `label => $case->value`，显示英文
 *   ③ 表单 `->using(Enum::class)` —— 从不填 `options`，下拉**空的**
 *
 * 而 `llms.txt` 明写着 `->using(EnumClass::class)  // 值→标签映射`。
 * 一个 agent 为此做了对照实验（给枚举逐个加 label/getLabel/title/labels 试），
 * 确认框架「一个都不调用」。
 *
 * 所以取标签的规则收敛到 `EnumLabel` 一处，这里把三条路径都钉住。
 */
class EnumLabelTest extends TestCase
{
    // ── ① 列表列 ──

    public function test_column_using_resolves_labels(): void
    {
        $node = (new Column('status', '状态'))->using(LabelledStatus::class)->toNode();

        $this->assertSame(['active' => '启用', 'disabled' => '禁用'], (array) $node->props['map']);
    }

    public function test_column_explicit_map_wins(): void
    {
        $node = (new Column('status', '状态'))
            ->using(LabelledStatus::class)
            ->map(['active' => '手写的'])
            ->toNode();

        $this->assertSame(['active' => '手写的'], (array) $node->props['map']);
    }

    // ── ② 表单 options(cases()) ──

    public function test_form_options_from_cases_uses_labels(): void
    {
        // ⚠️ 这条原先会红：label 写死成 value，下拉显示 active/disabled
        $node = (new Select('status', '状态'))->options(LabelledStatus::cases())->toNode();
        $opts = $node->props['options'];

        $this->assertSame(
            [['value' => 'active', 'label' => '启用'], ['value' => 'disabled', 'label' => '禁用']],
            $opts
        );
    }

    // ── ③ 表单 using(Enum::class) ──

    public function test_form_using_populates_options(): void
    {
        // ⚠️ 这条原先也会红：下拉里一个选项都没有
        $node = (new Select('status', '状态'))->using(LabelledStatus::class)->toNode();
        $opts = $node->props['options'];

        $this->assertCount(2, $opts);
        $this->assertSame(['value' => 'active', 'label' => '启用'], $opts[0]);
    }

    public function test_form_explicit_options_wins_over_using(): void
    {
        // 两种调用顺序都要以显式 options 为准
        $a = (new Select('s', 'S'))->using(LabelledStatus::class)->options(['x' => '我的'])->toNode();
        $b = (new Select('s', 'S'))->options(['x' => '我的'])->using(LabelledStatus::class)->toNode();

        $this->assertSame([['value' => 'x', 'label' => '我的']], $a->props['options']);
        $this->assertSame([['value' => 'x', 'label' => '我的']], $b->props['options']);
    }

    // ── 标签解析本身 ──

    public function test_get_label_is_also_supported(): void
    {
        $this->assertSame('开启', EnumLabel::of(GetLabelledStatus::On));
    }

    public function test_falls_back_to_case_name(): void
    {
        // `Active` 也比 `active` 可读
        $this->assertSame('Active', EnumLabel::of(BareStatus::Active));
    }

    public function test_value_handles_backed_and_pure(): void
    {
        $this->assertSame('active', EnumLabel::value(BareStatus::Active));
    }

    public function test_map_builds_the_whole_enum(): void
    {
        $this->assertSame(
            ['active' => '启用', 'disabled' => '禁用'],
            EnumLabel::map(LabelledStatus::class)
        );
    }

    // ── 顺带：rules 字符串必须按 | 拆（同一个「语义差异」家族） ──

    public function test_rules_string_is_split_on_pipe(): void
    {
        /*
         * Laravel 里这两种**不等价**：
         *     'a|b'   字符串 → 拆成两条规则
         *     ['a|b'] 数组   → 整串当成一个规则名 → validateA|b does not exist → 提交 500
         *
         * 而 rules() 以前用 (array) 包字符串，正好落进第二种。
         * 实测 agent 撞到，且 **ai:verify 照样报 valid=true**。
         */
        $rules = (new Text('email', '邮箱'))->required()->rules('nullable|in:a,b')->toNode()->rules;

        $this->assertSame(['required', 'nullable', 'in:a,b'], $rules);
    }

    public function test_rules_array_is_not_split_further(): void
    {
        $rules = (new Text('x', 'X'))->rules(['nullable', 'in:a,b'])->toNode()->rules;

        $this->assertSame(['nullable', 'in:a,b'], $rules);
    }

    public function test_rules_trims_and_ignores_empty(): void
    {
        $rules = (new Text('x', 'X'))->rules(' nullable | in:a,b | ')->toNode()->rules;

        $this->assertSame(['nullable', 'in:a,b'], $rules);
    }
}
