<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Form\Fields\Field;
use Aimanong\Form\Form;
use Aimanong\Support\FieldType;
use PHPUnit\Framework\TestCase;

/**
 * 插件字段类型注册测试。
 *
 * 这是「首个官方插件」验证出来的机制缺口：
 * 原来字段类型是硬编码常量，插件无法注入新字段。
 */
class PluginFieldTest extends TestCase
{
    protected function tearDown(): void
    {
        // 清理注册的测试类型，避免污染其它测试
        $ref = new \ReflectionClass(FieldType::class);

        foreach (['registered', 'fieldClasses'] as $name) {
            if ($ref->hasProperty($name)) {
                $prop = $ref->getProperty($name);
                $prop->setAccessible(true);
                $prop->setValue(null, []);
            }
        }

        parent::tearDown();
    }

    public function test_plugin_can_register_field_type(): void
    {
        $this->assertFalse(FieldType::exists('plugin_demo'));

        FieldType::register('plugin_demo', 'string', 'string');

        $this->assertTrue(FieldType::exists('plugin_demo'));
        $this->assertTrue(FieldType::isRegistered('plugin_demo'));
        $this->assertContains('plugin_demo', FieldType::all());
    }

    public function test_registered_type_maps_json_type(): void
    {
        FieldType::register('plugin_num', 'integer', 'int');

        $this->assertSame('integer', FieldType::toJsonType('plugin_num'));
        $this->assertSame('int', FieldType::toPhpType('plugin_num'));
    }

    public function test_form_can_instantiate_plugin_field(): void
    {
        FieldType::register('plugin_text', 'string', 'string', PluginDemoField::class);

        $form = new Form;
        $field = $form->plugin_text('foo', '示例');

        $this->assertInstanceOf(PluginDemoField::class, $field);
        $this->assertSame('foo', $field->getName());
        $this->assertSame('plugin_text', $field->toNode()->type);
    }

    /**
     * 关键：未注册的方法必须抛异常，不能是任意魔法入口。
     *
     * 框架铁律是「零隐式魔法」，__call 只服务已注册的字段类型。
     */
    public function test_unknown_method_throws_with_helpful_message(): void
    {
        $form = new Form;

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/可用字段类型/');

        $form->thisMethodDoesNotExist('x');
    }
}

/**
 * 测试用的插件字段。
 */
class PluginDemoField extends Field
{
    protected string $type = 'plugin_text';
}
