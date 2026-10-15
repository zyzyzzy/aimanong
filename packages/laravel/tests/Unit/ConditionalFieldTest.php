<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Form\Fields\Select;
use Aimanong\Form\Fields\Text;
use PHPUnit\Framework\TestCase;

/**
 * 条件显示（`visibleWhen`）的声明 → schema 契约。
 *
 * ## 为什么有这个测试
 *
 * 在此之前框架**没有**条件显示的声明方式（`hidden()` 只能静态隐藏）。
 * 实测代价：一个 LLM 为了做「选 A 显示 B」，翻遍 llms.txt、自省接口、
 * 反射了 142 个框架类，最后**自己写了一个扩展**（注册字段类型 + 注入 JS）。
 *
 * 也就是说：声明一句就该解决的事，变成了每个项目各自实现一遍 ——
 * 而且自定义实现框架不认识、不进 schema、不受校验。
 */
class ConditionalFieldTest extends TestCase
{
    public function test_visible_when_lands_in_props(): void
    {
        $field = new Select('priority', '优先级');
        $field->visibleWhen('type', 'bug');

        $this->assertSame(
            ['field' => 'type', 'value' => 'bug'],
            $field->getProps()['visibleWhen'] ?? null
        );
    }

    public function test_visible_when_accepts_array_of_values(): void
    {
        $field = new Select('priority', '优先级');
        $field->visibleWhen('type', ['bug', 'feature']);

        $this->assertSame(
            ['field' => 'type', 'value' => ['bug', 'feature']],
            $field->getProps()['visibleWhen'] ?? null
        );
    }

    public function test_visible_when_survives_field_node_conversion(): void
    {
        $field = new Select('priority', '优先级');
        $field->visibleWhen('type', 'bug');

        // 条件必须跟着 FieldNode 走到 schema —— 只存在组件里而没进 schema，
        // 前端就拿不到（这正是「声明了但没接上」的经典断法）
        $this->assertSame(
            ['field' => 'type', 'value' => 'bug'],
            $field->toNode()->props['visibleWhen'] ?? null
        );
    }

    public function test_without_visible_when_props_stay_clean(): void
    {
        $field = new Text('title', '标题');

        $this->assertArrayNotHasKey('visibleWhen', $field->getProps());
    }
}
