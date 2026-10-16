<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Capabilities;
use Aimanong\Ai\Verifier;
use Aimanong\Aimanong;
use Aimanong\Tests\Unit\Fixtures\DeadPropResource;
use Aimanong\Tests\Unit\Fixtures\FakeFrameworkRuntime;
use Aimanong\Tests\Unit\Fixtures\WiredPropResource;
use PHPUnit\Framework\TestCase;

/**
 * `ai:verify` 对「声明了但前端不读」的 prop **事前拦截**。
 *
 * ## 这条校验解决的是什么
 *
 * 框架的承诺是「声明即界面」。但有 13 个 prop 是**静默失效**的：
 * 能写、schema 里下发了、`ai:verify` 报 `valid: true` —— 前端一次没读过。
 *
 * 实测复现（2026-10-16）：
 *
 *     $form->text('name')->placeholder('请输入部门名称');
 *     → /__ai/schema 里确实有 props.placeholder
 *     → 页面 HTML 里搜这句话，出现 **0 次**
 *
 * 后果不是「少个功能」，而是 **AI 必须自己背下哪 13 个是坑** ——
 * 这正是「AI 不得不关心前端实现细节」的来源。
 *
 * ## 本测试的两个方向
 *
 * 1. **正例**：用了死 prop → 必须报 `UNWIRED_PROP`，且**必须带替代写法**
 * 2. **反例**：只用已接通的 prop → **不许**报 `UNWIRED_PROP`
 *
 * 第 2 条和门禁反例验证同等重要：一个「见谁都报」的校验会让 AI
 * 很快学会忽略它 —— 那比没有校验更糟。
 */
class UnwiredPropVerificationTest extends TestCase
{
    /**
     * 单测不起 Laravel 应用，而 `Verifier` 会调 `Aimanong::registry()`
     * —— 公开入口要求绑定运行时（否则抛未注册，那是刻意设计）。
     */
    protected function setUp(): void
    {
        parent::setUp();

        Aimanong::bindRuntime(new FakeFrameworkRuntime);
    }

    /**
     * 用了死 prop → 必须被拦，且每条都带可执行的下一步。
     */
    public function test_dead_props_are_blocked_with_a_replacement(): void
    {
        $result = (new Verifier)->verify(DeadPropResource::class);

        $this->assertFalse($result['valid'], '用了死 prop 的声明不该被判为合法');

        $codes = array_column($result['issues'], 'code');

        $this->assertContains('UNWIRED_PROP', $codes, '必须报 UNWIRED_PROP');

        $unwired = array_values(array_filter(
            $result['issues'],
            fn (array $i): bool => ($i['code'] ?? '') === 'UNWIRED_PROP'
        ));

        // 夹具用了 4 个死 prop（列 relation / 字段 allowHalf、separator、display）
        $this->assertCount(4, $unwired, '四个死 prop 应各报一条');

        $props = [];

        foreach ($unwired as $issue) {
            // ① 必须说清是「列表列」还是「表单字段」—— 同名 prop 在两边含义不同
            $this->assertMatchesRegularExpression(
                '/^(列表列|表单字段) \[[^\]]+\] 用了/',
                (string) $issue['message'],
                '消息必须指明位置（列还是字段）+ 名字，否则 AI 会改错地方'
            );

            // ② 必须给出替代写法 —— 只说「不生效」等于把问题丢回给 AI
            $this->assertArrayHasKey('did_you_mean', $issue, '必须带 did_you_mean（替代写法）');
            $this->assertNotSame('', trim((string) $issue['did_you_mean']));
            $this->assertNotNull($issue['did_you_mean'], '替代写法不能为 null');

            // ③ 必须有一句「它对用户是什么体验」
            $this->assertArrayHasKey('hint', $issue);

            // prop 名可能是 camelCase（allowHalf / trueLabel），字符类要含大写
            preg_match('/用了 \'([A-Za-z_][A-Za-z0-9_]*)\'/', (string) $issue['message'], $m);
            $props[] = $m[1] ?? '?';
        }

        sort($props);

        $this->assertSame(['allowHalf', 'display', 'relation', 'separator'], $props);
    }

    /**
     * 反例（负向对照）：只用已接通的 prop → **不许**报 UNWIRED_PROP。
     *
     * 见谁都报的校验会被忽略，比没有更糟。
     */
    public function test_wired_props_are_not_blocked(): void
    {
        $result = (new Verifier)->verify(WiredPropResource::class);

        $codes = array_column($result['issues'], 'code');

        $this->assertNotContains(
            'UNWIRED_PROP',
            $codes,
            '只用已接通的 prop 不该被拦 —— 误报会让这条校验失去意义'
        );
    }

    /**
     * `cannot_express` 必须真的进了能力清单 —— 光是方法存在没用。
     *
     * 这条防的是「写了方法忘了接线」：AI 读的是 toArray() 的输出，
     * 方法写得再好，没进 toArray() 就等于不存在。
     */
    public function test_cannot_express_reaches_the_capabilities_payload(): void
    {
        $caps = (new Capabilities)->toArray();

        $this->assertArrayHasKey('cannot_express', $caps);
        $this->assertArrayHasKey('unwired_props', $caps);

        $this->assertSame(
            array_keys(Capabilities::unwiredProps()),
            array_keys($caps['unwired_props']),
            '清单里的 unwired_props 必须与单一真值同键'
        );
    }

    /**
     * 拦截数据的单一真值必须是 `Capabilities::unwiredProps()`。
     *
     * 也就是说：**把某个 prop 接通后，两边会同时停止拦截**，
     * 不需要去改 Verifier。这条断言钉住这个耦合方式。
     */
    public function test_interception_reads_the_single_source_of_truth(): void
    {
        $dead = Capabilities::unwiredProps();
        $advice = Capabilities::unwiredPropReplacements();

        // ① 字段侧仍有这几个
        foreach (['allowHalf', 'separator', 'display'] as $prop) {
            $this->assertArrayHasKey($prop, $dead['field'], "{$prop} 应仍在字段侧债务清单里");
            $this->assertArrayHasKey($prop, $advice['field'], "{$prop} 缺替代写法");
        }

        // ② 列侧只剩 relation
        $this->assertArrayHasKey('relation', $dead['column']);
        $this->assertArrayHasKey('relation', $advice['column']);

        /*
         * ③ 反向：已经接通的 prop **必须不在**清单里（两侧都不在）。
         *
         * 这一条和正向同等重要 —— 接通了却还留在清单里，会导致
         * `ai:verify` 对**完全正常的写法**报错，AI 很快会学会忽略这条校验。
         */
        foreach (['placeholder', 'width', 'height', 'text', 'format', 'symbol', 'decimals'] as $wired) {
            $this->assertArrayNotHasKey($wired, $dead['column'], "{$wired} 已接通，列侧不该再拦");
            $this->assertArrayNotHasKey($wired, $dead['field'], "{$wired} 已接通，字段侧不该再拦");
        }

        /*
         * ④ **位置必须区分** —— 这是本批最关键的一条修正。
         *
         * `relation` 在列上没人读，但在字段上**接通了**
         * （Compiler 收进 meta.relationFields，Controller 拿它 sync 中间表）。
         * 不分位置就会对 `$form->multiSelect(..)->relation(..)` 误报 ——
         * 实测在 demo 上炸出 4 个假阳性。
         */
        $this->assertArrayHasKey('relation', $dead['column'], '列上的 relation 确实是死的');
        $this->assertArrayNotHasKey('relation', $dead['field'], '字段上的 relation 是活的，不许拦');
    }
}
