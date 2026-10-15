<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Form\Fields\Select;
use Aimanong\Support\MethodSuggester;
use Aimanong\Tree\Tree;
use BadMethodCallException;
use PHPUnit\Framework\TestCase;

/**
 * 「方法名猜错」时的可自愈报错。
 *
 * ## 为什么有这个测试
 *
 * 实测：一个 LLM 想给字段加「满足条件才显示」，猜 `showWhen()` —— 真名是
 * `visibleWhen()`。PHP 原生只回一句 `Call to undefined method`，
 * 于是它**反射了 142 个框架类**才确认「不是文档漏写，是真的没有」。
 *
 * 差别不在模型强弱，只在**框架有没有把已经知道的信息说出来**。
 */
class MethodSuggesterTest extends TestCase
{
    public function test_typo_gets_levenshtein_suggestion(): void
    {
        $names = ['required', 'readonly', 'options', 'label'];

        $this->assertContains('required', MethodSuggester::closestNames('requried', $names));
    }

    public function test_semantic_near_miss_gets_suffix_suggestion(): void
    {
        /*
         * 这是最关键的一条：`showWhen` 想表达「满足条件才显示」，
         * 真名是 `visibleWhen`。两者编辑距离 6、字符串相似度 0.53 ——
         * 它**不是拼写错误**，任何编辑距离阈值都够不着。
         * 但它们共享后缀 `when`，靠这条判据才能建议出来。
         */
        $names = ['hidden', 'label', 'options', 'visibleWhen'];

        $suggested = MethodSuggester::closestNames('showWhen', $names);

        $this->assertContains('visibleWhen', $suggested, 'showWhen 应被建议到 visibleWhen');
    }

    public function test_garbage_produces_no_suggestion(): void
    {
        // 反向断言：乱码不该被「建议」成某个真实方法 ——
        // 宁可没有建议，也不要给一个误导性的
        $this->assertSame([], MethodSuggester::closestNames('xyz123abc', ['required', 'label', 'options']));
    }

    public function test_message_preserves_original_case(): void
    {
        // 建议里必须回显**真实存在**的写法（visibleWhen），
        // 不能是小写的 visiblewhen —— 那不是代码里能写的东西
        $message = MethodSuggester::message(Select::class, 'showWhen');

        $this->assertStringContainsString('visibleWhen()', $message);
        $this->assertStringNotContainsString('visiblewhen()', $message);
    }

    public function test_message_lists_available_methods(): void
    {
        $message = MethodSuggester::message(Select::class, 'nopeNopeNope');

        $this->assertStringContainsString('可用方法', $message);
        $this->assertStringContainsString('visibleWhen', $message);
    }

    public function test_extra_names_are_merged_into_suggestions(): void
    {
        // Form 的字段类型不在方法表里，必须靠 $extra 参与匹配
        $message = MethodSuggester::message(Select::class, 'labeel', ['label'], '可用字段类型');

        $this->assertStringContainsString('label()', $message);
        $this->assertStringContainsString('可用字段类型', $message);
    }

    public function test_unknown_method_on_field_throws_bad_method_call(): void
    {
        $this->expectException(BadMethodCallException::class);

        (new Select('priority', '优先级'))->thisIsNotAMethod('x');
    }

    public function test_unknown_method_on_tree_throws_bad_method_call(): void
    {
        $this->expectException(BadMethodCallException::class);

        (new Tree)->notATreeMethod('x');
    }
}
