<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Capabilities;
use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use PHPUnit\Framework\TestCase;

/**
 * 「声明的 prop 有没有消费方」门禁。
 *
 * ## 为什么要单独一个测试
 *
 * 本项目历史上最多次踩到的模式是：
 *
 *     声明了 ->xxx()、schema 里也下发了 props.xxx，
 *     但**前端一次都没读过它** —— 既不生效，也不报错。
 *
 * 而 `ai:verify` 只校验「声明本身合法」，**看不见有没有消费方**；
 * 单测也只断言声明层 getter（`assertSame(200, $col->getWidth())`），
 * 同样看不见。于是这类缺陷能一路穿到用户面前。
 *
 * 2026-10-16 审计一次就点出 13 个这样的 API（width / symbol /
 * separator / allowHalf / decimals / disk / placeholder / …），
 * 而当时全部门禁都是绿的。
 *
 * ## 这个测试怎么工作
 *
 * 1. 把能力面上能用的 API **都调一遍**，收集它们下发的 props 键；
 * 2. 扫前端模板（resource.blade.php / upload.blade.php）里的读法
 *    `props.X` / `props?.X`；
 * 3. 差集必须为空 —— 除非它在 KNOWN_NO_CONSUMER 里**显式登记**。
 *
 * ## KNOWN_NO_CONSUMER 的语义
 *
 * 它不是「允许清单」，是**债务清单**：
 * 登记在这里 = 「我们知道它没接前端，且没有对用户宣称它生效」。
 * 新增未接线的 API 会**直接让这个测试红**，逼作者做三选一：
 * 接前端、从能力面撤下、或显式登记进债务清单。
 */
class PropConsumerTest extends TestCase
{
    /**
     * 已知「下发但不消费」的 prop —— 债务清单，不是许可。
     *
     * 每一项都要写清：为什么不接、现在算不算对用户宣称生效。
     *
     * @var array<string, string>
     */
    private const KNOWN_NO_CONSUMER = [
        /*
         * 全部来自 2026-10-16 审计（一次就点出 13 个，而当时全部门禁是绿的）。
         *
         * 每一项写清：**它对用户是什么体验**。
         * 登记在这里的 prop 会同时出现在 capabilities.json 的 `unwired_props` 段，
         * 让 AI 在读能力面时就知道「这条声明目前不生效」—— 把静默能力变成显式限制。
         */
        'allowHalf' => '->rate()->allowHalf()：界面只画整星，半星选择无效',
        'decimals' => '->money()->decimals(n)：界面固定两位小数；导出侧也写死 2',
        'disk' => '->disk(盘名)：上传盘只取 config，不读字段声明',
        'display' => '->display()：前端未按此渲染只读展示（走的是字段 type 分支）',
        'format' => '->dateTime(格式)：时间格式写死 slice(0,19)',
        'height' => '->image(n)：图片高度未接到样式绑定',
        'min' => '->number()->min(n)：最小值未下发到 input 的 min 属性',
        'placeholder' => '->placeholder()：文案未接到 input 的 placeholder',
        'relation' => '->relation(关联路径)：前端只认列名里的点号；显式声明是空操作',
        'separator' => '->tags()->separator()：分隔符未接线',
        'symbol' => '->money(符号)：界面不显示货币符号（导出侧读了）',
        'text' => '->link(文案)：链接文案写死「打开 ↗」',
        'width' => '->width(n)：列宽未接到样式绑定',
    ];

    /**
     * 把能力面上能用的 API 都调一遍，收集 props 键。
     */
    public function test_every_emitted_prop_has_a_frontend_reader(): void
    {
        $props = $this->emittedProps();

        $this->assertNotEmpty($props, '没收集到任何 prop —— 这个测试本身失效了');

        $readers = $this->frontendReadProps();

        $this->assertNotEmpty($readers, '没扫到任何前端读法 —— 这个测试本身失效了');

        $unwired = array_values(array_diff($props, $readers));
        sort($unwired);

        $undeclared = array_values(array_diff($unwired, array_keys(self::KNOWN_NO_CONSUMER)));
        sort($undeclared);

        $this->assertSame(
            [],
            $undeclared,
            "以下 prop 下发了但前端从不读取，也不在 KNOWN_NO_CONSUMER 债务清单里：\n  - "
            .implode("\n  - ", $undeclared)
            ."\n\n修法三选一：①接前端 ②从能力面撤下 ③显式登记进 PropConsumerTest::KNOWN_NO_CONSUMER"
        );
    }

    /**
     * 债务清单不能腐坏：登记过的 prop 如果已经被接上，就该从清单里删掉。
     */
    public function test_debt_list_does_not_rot(): void
    {
        $readers = $this->frontendReadProps();
        $stale = [];

        foreach (array_keys(self::KNOWN_NO_CONSUMER) as $prop) {
            if (in_array($prop, $readers, true)) {
                $stale[] = $prop;
            }
        }

        $this->assertSame(
            [],
            $stale,
            '这些 prop 已经被前端读了，请从 KNOWN_NO_CONSUMER 里删掉（否则债清单会骗人）：'
            .implode(', ', $stale)
        );
    }

    /**
     * 已知未接线的 prop 名单对外可见（Capabilities 会读它）。
     *
     * 两处各写一遍必然漂移 —— 这个用例就是那颗钉子。
     */
    public function test_known_unwired_list_is_exposed(): void
    {
        $listed = Capabilities::unwiredProps();

        $this->assertSame(
            array_keys(self::KNOWN_NO_CONSUMER),
            array_keys($listed),
            'Capabilities::unwiredProps() 与测试里的债务清单必须一致 —— 两处各写一遍必然漂移'
        );
    }

    /**
     * 调用一批 API，收集它们下发的所有 props 键。
     *
     * 注意：`->dict()` 不在这里 —— 它对不存在的字典会直接抛
     * DictNotFoundException（那是**对的**行为，字典本来就是强校验项），
     * 所以它不需要靠这个测试来兜底。
     *
     * @return array<int, string>
     */
    private function emittedProps(): array
    {
        $props = [];

        /*
         * 每个 API **单独调**，不串长链。
         *
         * 串链时一个方法返回了非对象（例如 startColumn() 其实返回 string），
         * 整条链就断在那里，后面所有 prop 都收集不到 ——
         * 测试会以为「没这些 prop」，从而漏报。分开调就没有这个问题。
         */
        $grid = new Grid;
        $col = $grid->column('probe', '探针');
        $this->collect($col->toNode()->props, $props);

        $calls = [
            'sortable' => fn () => $col->sortable(),
            'searchable' => fn () => $col->searchable(),
            'width' => fn () => $col->width(120),
            'money' => fn () => $col->money('$'),
            'image' => fn () => $col->image(80),
            'link' => fn () => $col->link('去查看'),
            'dateTime' => fn () => $col->dateTime('Y/m/d'),
            'badge' => fn () => $col->badge(),
            'progress' => fn () => $col->progress(),
            'bool' => fn () => $col->bool('是', '否'),
            'relation' => fn () => $col->relation('probe_rel.name'),
            'dangerWhen' => fn () => $col->dangerWhen('>', 100),
        ];

        foreach ($calls as $api) {
            /*
             * 逐个 try —— 某个 API 不存在**不该**让整个探针失效。
             * 那会变成「测试红得莫名其妙」，而不是「告诉你哪个 prop 没接线」。
             * （实测踩到：SwitchField 没有 trueLabel()，异常直接把整轮扫断。）
             */
            try {
                $api();
            } catch (\Throwable) {
                continue;
            }

            $this->collect($col->toNode()->props, $props);
        }

        $form = new Form;

        $formCalls = [
            'text.placeholder' => fn () => $form->text('a')->placeholder('占位'),
            'text.readonly' => fn () => $form->text('b')->readonly(),
            'image.multiple' => fn () => $form->image('c')->multiple(),
            'image.disk' => fn () => $form->image('c')->disk('s3'),
            'tags.separator' => fn () => $form->tags('e')->separator('|'),
            'rate.allowHalf' => fn () => $form->rate('f')->allowHalf(),
            'money.symbol' => fn () => $form->money('g')->symbol('€'),
            'money.decimals' => fn () => $form->money('g')->decimals(4),
            'select.multiple' => fn () => $form->select('h')->multiple(),
            'date.format' => fn () => $form->date('j')->dateFormat('Y/m/d'),
            'textarea.rows' => fn () => $form->textarea('l')->rows(6),
            'display.help' => fn () => $form->display('o')->help('说明'),
            'slider.range' => fn () => $form->slider('m')->range(1, 5),
            'number.min' => fn () => $form->number('s')->min(1),
        ];

        foreach ($formCalls as $api) {
            try {
                $api();
            } catch (\Throwable) {
                continue;
            }

            $this->walk($form->toArray(), $props);
        }

        $g = new Grid;
        $g->column('x');

        foreach (['actions' => fn () => $g->actions(false)] as $api) {
            try {
                $api();
            } catch (\Throwable) {
                continue;
            }

            $this->walk($g->toArray(), $props);
        }

        return array_values(array_unique($props));
    }

    /**
     * @param  array<mixed>  $nodeProps
     * @param  array<int, string>  $props
     */
    private function collect(array $nodeProps, array &$props): void
    {
        foreach (array_keys($nodeProps) as $k) {
            $props[] = (string) $k;
        }
    }

    /**
     * @param  array<mixed>  $arr
     * @param  array<int, string>  $props
     */
    private function walk(array $arr, array &$props): void
    {
        foreach ($arr as $k => $v) {
            if ($k === 'props' && is_array($v)) {
                foreach (array_keys($v) as $pk) {
                    $props[] = (string) $pk;
                }

                continue;
            }

            if (is_array($v)) {
                $this->walk($v, $props);
            }
        }
    }

    /**
     * 扫前端模板里读到的 props 键。
     *
     * 支持三种写法：`props.X`、`props?.X`、`props['X']`。
     *
     * @return array<int, string>
     */
    private function frontendReadProps(): array
    {
        $dir = dirname(__DIR__, 2).'/resources/views';
        $files = [];

        foreach (['resource.blade.php', 'partials/upload.blade.php', 'profile.blade.php'] as $rel) {
            $path = $dir.'/'.$rel;

            if (is_file($path)) {
                $files[] = (string) file_get_contents($path);
            }
        }

        $this->assertNotEmpty($files, '读不到前端模板 —— 路径变了？');

        $props = [];

        foreach ($files as $src) {
            // props.X / props?.X
            preg_match_all('/props\??\.([a-zA-Z_][a-zA-Z0-9_]*)/', $src, $m);

            foreach ($m[1] as $k) {
                $props[] = $k;
            }

            // props['X'] / props?.['X']
            preg_match_all('/props\??\.?\[\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*\]/', $src, $m2);

            foreach ($m2[1] as $k) {
                $props[] = $k;
            }
        }

        // 前端还会读一些不在 props 里的字段（type/name/label…），
        // 那些不是「能力面 prop」，收集时本来就不会出现，不用管。
        return array_values(array_unique($props));
    }
}
