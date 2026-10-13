<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use PHPUnit\Framework\TestCase;

/**
 * 表单「选项」的数据契约测试。
 *
 * ## 为什么要有这个测试
 *
 * 真实缺陷（v1.4.1 之前一直存在，v1.5.0 修复）：
 *
 * 1. Schema 编译层把选项统一输出成 **数组**：
 *    `[{"value": 1, "label": "Laravel"}, ...]`
 *    （`OpenApiEmitter` 直接 `array_column($options, 'value')`，可见契约就是数组）
 *
 * 2. 但前端表单模板把它当 **对象** 遍历：
 *    `<option v-for="(text, val) in f.props.options">`
 *
 *    Vue 对数组做 `(value, key)` 解构时，`key` 是**下标**、`value` 是**整个元素**。
 *    于是：
 *    - 下拉框把 `{ "value": 1, "label": "Laravel" }` 原样打印成文案；
 *    - 每个选项的 `:value` 变成 `0 / 1 / 2 …`；
 *    - 带默认值的 select 永远匹配不上 → `selectedIndex = -1` → 显示空白；
 *    - 必填下拉框因此**永远保存不了**，用户只知道「保存失败」。
 *
 * 这类错误不会让 PHP 报错、也不会让 Schema 校验失败，
 * 只会在浏览器里静静地渲染出一堆 JSON。
 * 所以这里把「选项必须是数组」和「模板必须走 fieldOptions()」
 * 两条契约都钉死在测试里。
 */
class FormOptionContractTest extends TestCase
{
    /** @return array<string, mixed> */
    protected function emittedForm(): array
    {
        /** @var class-string<\Aimanong\Contracts\Resource> $class */
        $class = Fixtures\OptionFormResource::class;

        $node = (new Compiler)->compile($class);

        $json = (new JsonSchemaEmitter)->emit($node);

        return $json['form'];
    }

    public function test_options_are_emitted_as_list_of_value_label_pairs(): void
    {
        $form = $this->emittedForm();

        foreach ($form['fields'] as $field) {
            $options = $field['props']['options'] ?? null;

            if ($options === null) {
                continue;
            }

            $this->assertIsList($options, "字段 {$field['name']} 的 options 必须是列表，不能是 {值: 文案} 映射表");

            foreach ($options as $option) {
                $this->assertIsArray($option, "字段 {$field['name']} 的每个选项必须是对象");
                $this->assertArrayHasKey('value', $option, "字段 {$field['name']} 的选项缺少 value");
                $this->assertArrayHasKey('label', $option, "字段 {$field['name']} 的选项缺少 label");
            }
        }
    }

    public function test_select_default_matches_one_of_the_option_values(): void
    {
        $form = $this->emittedForm();

        $status = null;
        foreach ($form['fields'] as $field) {
            if ($field['name'] === 'status') {
                $status = $field;
            }
        }

        $this->assertNotNull($status, '未找到 status 字段');
        $this->assertSame('draft', $status['default']);

        $values = array_column($status['props']['options'], 'value');
        $this->assertContains(
            $status['default'],
            $values,
            '默认值必须能在选项里找到，否则 <select> 的 selectedIndex 会变成 -1（显示空白）'
        );
    }

    /**
     * 前端模板必须通过 fieldOptions() 取选项。
     *
     * 直接遍历 `f.props.options` 会退化成上面注释里描述的 bug，
     * 所以这里对模板文本做一次「禁止写法」检查。
     */
    public function test_resource_view_consumes_options_through_helper(): void
    {
        $view = file_get_contents(__DIR__.'/../../resources/views/resource.blade.php');
        $this->assertIsString($view);

        $this->assertStringContainsString('fieldOptions(f)', $view);

        $this->assertStringNotContainsString(
            'in (f.props.options',
            $view,
            '不要直接遍历 f.props.options —— 它是数组，必须经 fieldOptions() 归一化'
        );
    }
}
