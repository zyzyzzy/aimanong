<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Capabilities;
use Aimanong\Exceptions\DictNotFoundException;
use Aimanong\Form\Form;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Mcp\Tools\SearchDocs;
use Laravel\Mcp\Request;
use PHPUnit\Framework\TestCase;

/**
 * 数据字典测试。
 *
 * 单测环境没有 Laravel 容器，读不到 config/DB，
 * 因此这里通过构造函数注入声明来覆盖字典的**逻辑**
 * （排序、停用过滤、配色、模糊建议）；
 * 「配置 → 字典」那一段由真机验证覆盖。
 */
class DictionaryTest extends TestCase
{
    protected function dictionary(): Dictionary
    {
        return new Dictionary([
            'order_status' => [
                'name' => '订单状态',
                'items' => [
                    ['value' => 'pending', 'label' => '待付款', 'color' => 'warning', 'sort' => 20],
                    ['value' => 'paid', 'label' => '已付款', 'color' => 'success', 'sort' => 10],
                    ['value' => 'closed', 'label' => '已关闭', 'color' => 'muted', 'sort' => 30, 'enabled' => false],
                ],
            ],
            'simple' => [
                'name' => '简单字典',
                'items' => ['a' => '甲', 'b' => '乙'],
            ],
        ]);
    }

    public function test_options_are_sorted_and_disabled_items_are_excluded(): void
    {
        $options = $this->dictionary()->options('order_status');

        // sort=10 的 paid 应排在 sort=20 的 pending 前面
        $this->assertSame(['paid', 'pending'], array_column($options, 'value'));

        // enabled=false 的 closed 不出现 —— 停用项出现在表单里等于让用户选到废值
        $this->assertNotContains('closed', array_column($options, 'value'));
    }

    public function test_options_carry_color(): void
    {
        $options = $this->dictionary()->options('order_status');

        $this->assertSame('success', $options[0]['color']);
        $this->assertSame('warning', $options[1]['color']);
    }

    public function test_map_and_label(): void
    {
        $dictionary = $this->dictionary();

        $this->assertSame(['paid' => '已付款', 'pending' => '待付款'], $dictionary->map('order_status'));
        $this->assertSame('已付款', $dictionary->label('order_status', 'paid'));
        $this->assertNull($dictionary->label('order_status', 'closed'), '停用项不参与翻译');
        $this->assertNull($dictionary->label('order_status', null));
        $this->assertNull($dictionary->label('order_status', ''));
    }

    public function test_colors_map_only_includes_items_with_color(): void
    {
        $this->assertSame(
            ['paid' => 'success', 'pending' => 'warning'],
            $this->dictionary()->colors('order_status')
        );
    }

    public function test_plain_map_items_are_normalized(): void
    {
        $options = $this->dictionary()->options('simple');

        $this->assertSame(['a', 'b'], array_column($options, 'value'));
        $this->assertSame(['甲', '乙'], array_column($options, 'label'));
    }

    public function test_code_declared_dict_is_locked(): void
    {
        $dictionary = $this->dictionary();

        $this->assertTrue($dictionary->isLocked('order_status'));
        $this->assertFalse($dictionary->isLocked('not_declared'));
    }

    public function test_all_describes_every_dictionary(): void
    {
        $all = $this->dictionary()->all();

        $this->assertArrayHasKey('order_status', $all);
        $this->assertArrayHasKey('simple', $all);
        $this->assertSame('code', $all['order_status']['source']);
        $this->assertSame('订单状态', $all['order_status']['name']);
        $this->assertSame(2, $all['order_status']['count']);
        $this->assertSame('简单字典', $all['simple']['name']);
    }

    /**
     * 单一来源：新增基座能力必须同时出现在 capabilities 与 search-docs。
     * 本项目被 AI 实测打脸过三次的坑。
     */
    public function test_dict_is_exposed_in_capabilities_and_search_docs(): void
    {
        $foundation = (new Capabilities)->foundationCapabilities();

        $this->assertArrayHasKey('dict', $foundation);
        $this->assertStringContainsString('dict(', (string) $foundation['dict']['declare']);
        $this->assertSame(['admin-dict-types', 'admin-dict-items'], $foundation['dict']['uris']);

        $text = (new SearchDocs)->handle(new Request([]))
            ->content()->toArray()['text'] ?? '';

        $this->assertStringContainsString('dict()', $text, 'search-docs 缺少 dict()');
        $this->assertStringContainsString('admin-dict-types', $text, 'search-docs 缺少字典 Resource');
    }

    public function test_unknown_dictionary_is_empty_not_error(): void
    {
        $dictionary = $this->dictionary();

        $this->assertFalse($dictionary->has('nope'));
        $this->assertSame([], $dictionary->options('nope'));
        $this->assertSame([], $dictionary->map('nope'));
        // 名字退化用 code 本身，而不是抛异常 —— 后台列表仍要能显示
        $this->assertSame('nope', $dictionary->name('nope'));
    }

    /**
     * 拼错字典 code 必须**当场报错**，而不是给一个永远选不了值的空下拉框。
     *
     * 这是「不提供只看起来能用的能力」这条铁律在字典上的体现。
     */
    public function test_missing_dict_throws_with_suggestion(): void
    {
        $form = new Form;

        try {
            $form->select('status')->dict('order_stauts');
            $this->fail('拼错的字典 code 必须抛异常');
        } catch (DictNotFoundException $e) {
            $ctx = $e->toArray();

            $this->assertSame('DICT_NOT_FOUND', $e::errorCode());
            $this->assertSame('order_stauts', $ctx['dict_code']);
            $this->assertArrayHasKey('did_you_mean', $ctx);
            $this->assertArrayHasKey('available_dicts', $ctx);
            $this->assertArrayHasKey('example', $ctx);
        }
    }

    /**
     * 字典缺失时的提示必须能让 AI 自愈（给出可选字典与示例）。
     */
    public function test_suggestion_picks_closest_code(): void
    {
        $e = new DictNotFoundException('x', 'priorty', ['priority', 'article_status']);
        $ctx = $e->toArray();

        $this->assertSame('priority', $ctx['did_you_mean']);
        $this->assertStringContainsString("dict('priority')", (string) $ctx['example']);

        // available_dicts 排序稳定，便于 AI 比对
        $this->assertSame(['article_status', 'priority'], $ctx['available_dicts']);
    }
}
