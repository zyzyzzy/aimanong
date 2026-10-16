<?php

declare(strict_types=1);

namespace Aimanong\Region\Tests\Unit;

use Aimanong\Region\Fields\RegionField;
use Aimanong\Region\RegionExtension;
use Aimanong\Support\FieldType;
use PHPUnit\Framework\TestCase;

/**
 * region 字段类型 + 扩展注册契约。
 *
 * ## 这个测试在验什么
 *
 * `RegionField` 继承框架的 `Aimanong\Form\Fields\Field`，由
 * `RegionExtension::register()` 注册成 `region` 类型 ——
 * **框架核心一行都不用改**。这是「插件能注入字段类型」的活证据。
 *
 * `Extension` 没有自定义构造函数，`register()` 也只调 `FieldType::register()`
 * （core 里的纯静态方法），所以**不需要 Laravel 运行环境**就能测真实的
 * 注册链路 —— 而不是只测一个孤立的字段类。
 *
 * ## 为什么必须走真实注册
 *
 * `Field::__construct()` 会校验类型是否已注册
 * （未注册直接抛 `UnknownFieldTypeException`）。
 * 所以如果 `register()` 里写的类型名与 `RegionField::$type` 不一致，
 * 使用者拿到的是「`$form->region()` 编译失败」——
 * 而报错信息指不到真正的错处。这条测试让它在单测阶段就红。
 */
class RegionFieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 走真实注册链路，不手工造 FieldType 状态
        (new RegionExtension)->register();
    }

    public function test_extension_registers_the_region_type(): void
    {
        $this->assertTrue(
            FieldType::isRegistered('region'),
            'RegionExtension::register() 必须把 region 注册进 FieldType'
        );

        $this->assertSame(RegionField::class, FieldType::fieldClass('region'));
    }

    public function test_extension_metadata(): void
    {
        $ext = new RegionExtension;

        $this->assertSame('region', $ext->name());
        $this->assertSame('省市区选择器', $ext->title());
        $this->assertSame('1.0.0', $ext->version());
        $this->assertNotSame('', $ext->description());
        $this->assertSame([], $ext->dependencies());
    }

    /**
     * composer.json 里声明的扩展类必须真的存在且是 Extension 子类。
     *
     * 这一条盯的是「声明了但没人消费」：`extra.aimanong.extension`
     * 写错一个字符，框架就找不到扩展，而症状是「字段编译失败」——
     * 与注册问题长得一模一样，很难查。
     */
    public function test_composer_extra_points_at_a_real_extension(): void
    {
        $json = json_decode(
            (string) file_get_contents(__DIR__.'/../../composer.json'),
            true
        );

        $class = $json['extra']['aimanong']['extension'] ?? null;

        $this->assertIsString($class, 'composer.json 必须声明 extra.aimanong.extension');
        $this->assertTrue(class_exists($class), "声明的扩展类 {$class} 不存在");
        $this->assertInstanceOf(RegionExtension::class, new $class);
    }

    public function test_type_name_matches_registration(): void
    {
        // Extension::register() 里写的是 'region'，必须一致
        $this->assertSame('region', (new RegionField('area'))->getType());
    }

    public function test_default_level_is_three(): void
    {
        $field = new RegionField('area');

        $this->assertSame(3, $field->getProps()['level'] ?? null, '默认省市区三级');
    }

    public function test_city_level_switches_to_two(): void
    {
        $field = (new RegionField('area'))->cityLevel();

        $this->assertSame(2, $field->getProps()['level']);
    }

    /**
     * level() 必须夹在 1..3 —— 越界值会让前端级联逻辑失序，
     * 而且不会报错（只是下拉少一层/多一层）。
     */
    public function test_level_is_clamped(): void
    {
        $this->assertSame(1, (new RegionField('a'))->level(0)->getProps()['level']);
        $this->assertSame(1, (new RegionField('a'))->level(-5)->getProps()['level']);
        $this->assertSame(3, (new RegionField('a'))->level(9)->getProps()['level']);
    }

    public function test_store_code_prop(): void
    {
        $field = new RegionField('area');

        // 默认存名称
        $this->assertArrayNotHasKey('storeCode', $field->getProps());

        $this->assertTrue($field->storeCode()->getProps()['storeCode']);
        $this->assertFalse($field->storeCode(false)->getProps()['storeCode']);
    }

    public function test_data_prop_passes_tree_through(): void
    {
        $tree = [['code' => 'A', 'name' => '甲省', 'children' => []]];

        $field = (new RegionField('area'))->data($tree);

        $this->assertSame($tree, $field->getProps()['data']);
    }

    public function test_chainable(): void
    {
        $field = (new RegionField('area'))->cityLevel()->storeCode()->data([]);

        $this->assertInstanceOf(RegionField::class, $field);
    }

    /**
     * 字段必须能编译成 FieldNode（进 Schema 的最后一步）。
     */
    public function test_compiles_to_node(): void
    {
        $node = (new RegionField('area', '所在地区'))->cityLevel()->toNode();

        $this->assertSame('area', $node->name);
        $this->assertSame('region', $node->type);
        $this->assertSame('所在地区', $node->label);
    }
}
