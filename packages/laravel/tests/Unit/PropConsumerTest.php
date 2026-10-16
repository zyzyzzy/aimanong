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
     * 每一项都要写清：它对用户是什么体验。
     */
    /**
     * 债务清单的**列侧**（column scope）。
     *
     * ⚠️ 必须带位置：同一个 prop 名在列与字段上命运可能不同 ——
     * `relation` 在列上没人读，在字段上是**接通**的（多对多 sync 用它）。
     */
    private const KNOWN_NO_CONSUMER_COLUMN = [
        'relation' => '->relation(关联路径)：列上只认列名里的点号，显式声明是空操作',
    ];

    /**
     * 债务清单的**字段侧**（field scope）。
     */
    private const KNOWN_NO_CONSUMER_FIELD = [
        'allowHalf' => '->rate()->allowHalf()：界面只画整星，半星选择无效',
        'disk' => '->disk(盘名)：上传盘只取 config，不读字段声明',
        'display' => '->display()：只读展示不按此渲染（前端走的是字段 type 分支）',
        'separator' => '->tags()->separator()：分隔符未接线（且 tags 本身无渲染分支）',
    ];

    /**
     * 全部债务（拍平后的 prop 名 → 体验描述）。
     *
     * @return array<string, string>
     */
    private static function debt(): array
    {
        return self::KNOWN_NO_CONSUMER_COLUMN + self::KNOWN_NO_CONSUMER_FIELD;
    }

    /**
     * 取某一侧的债务清单。
     *
     * @return array<string, string>
     */
    private static function debtScope(string $scope): array
    {
        return $scope === 'column' ? self::KNOWN_NO_CONSUMER_COLUMN : self::KNOWN_NO_CONSUMER_FIELD;
    }

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

        $undeclared = array_values(array_diff($unwired, array_keys(self::debt())));
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

        foreach (array_keys(self::debt()) as $prop) {
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

        // 按位置分别比对 —— 拍平会比出假差异（同名 prop 可能只在某一侧）
        $this->assertSame(
            array_keys(self::KNOWN_NO_CONSUMER_COLUMN),
            array_keys($listed['column'] ?? []),
            "Capabilities::unwiredProps()['column'] 与测试的列侧清单必须一致"
        );

        $this->assertSame(
            array_keys(self::KNOWN_NO_CONSUMER_FIELD),
            array_keys($listed['field'] ?? []),
            "Capabilities::unwiredProps()['field'] 与测试的字段侧清单必须一致"
        );
    }

    /**
     * 每个死 prop 都必须有**替代写法**。
     *
     * ## 为什么这是硬要求
     *
     * `ai:verify` 拦到死 prop 时会输出 `did_you_mean` —— 那正是 AI 的下一步。
     * 缺一条，AI 就只看到「不生效」四个字，然后退回反射框架去猜
     * （实测有过反射 142 个类的事故）。
     *
     * 所以：**新增债务登记必须同时给出替代**，否则就是让 AI 自己想办法。
     */
    public function test_every_unwired_prop_has_a_replacement(): void
    {
        $replacements = Capabilities::unwiredPropReplacements();

        foreach (['column', 'field'] as $scope) {
            $this->assertSame(
                array_keys(self::debtScope($scope)),
                array_keys($replacements[$scope] ?? []),
                "替代写法表 [{$scope}] 与债务清单的键必须一一对应"
                    .'（少一条 = 那个 prop 被拦时没有下一步）'
            );

            foreach ($replacements[$scope] ?? [] as $prop => $advice) {
                $this->assertNotSame('', trim((string) $advice), "{$scope}.{$prop} 的替代写法不能是空的");
            }
        }
    }

    /**
     * 「词表表达不了什么」这一节必须存在且非空。
     *
     * 它是 AI 判断「这个需求要写扩展」的唯一依据 ——
     * 缺了它，AI 只能在「框架是不是有我没找到的写法」上反复试错。
     */
    public function test_cannot_express_section_is_exposed(): void
    {
        // ⚠️ 这里直接调方法，不走 toArray() ——
        // toArray() 会连带读 registry()/版本信息，那需要绑定框架运行时，
        // 而这条测试只关心「这一段存在且内容合格」。
        $cannot = (new Capabilities)->cannotExpress();

        $this->assertNotEmpty($cannot);

        // 必须包含逃生口 —— 只说「做不到」而不说「怎么办」等于没帮上
        $this->assertStringContainsString(
            '逃生口',
            implode(' ', array_keys($cannot)),
            'cannot_express 必须包含「逃生口」条目'
        );

        // 几条已知的真实边界，断言在册（防止有人删掉它们）
        foreach (['富文本', '图表', '主子表', '详情页'] as $keyword) {
            $this->assertStringContainsString(
                $keyword,
                implode(' ', array_keys($cannot)),
                "cannot_express 应包含「{$keyword}」这条边界"
            );
        }
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
