<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Schema\Compiler;
use Aimanong\Support\FieldType;
use PHPUnit\Framework\TestCase;

/**
 * 字段类型与列展示器测试（M4）。
 *
 * 覆盖：
 *   - 新增字段类型可被 Form 创建并编译
 *   - 列展示器正确写入 formatter
 *   - 布尔/金额等语义展示器
 */
class FieldTypesTest extends TestCase
{
    /**
     * 所有已注册的字段类型都必须能实例化。
     *
     * 防止"注册表里有、但没有对应类"的漂移。
     */
    public function test_every_registered_type_has_class(): void
    {
        $missing = [];

        foreach (FieldType::all() as $type) {
            $class = match ($type) {
                'switch' => 'Aimanong\\Form\\Fields\\SwitchField',
                'multiselect' => 'Aimanong\\Form\\Fields\\MultiSelect',
                'daterange' => 'Aimanong\\Form\\Fields\\DateRange',
                'divider' => 'Aimanong\\Form\\Fields\\Divider',
                default => 'Aimanong\\Form\\Fields\\'.ucfirst($type),
            };

            if (! class_exists($class)) {
                $missing[] = "{$type} → {$class}";
            }
        }

        $this->assertSame([], $missing, '以下字段类型缺少实现类: '.implode(', ', $missing));
    }

    /**
     * 每个字段类型都要有对应的 JSON 类型映射。
     */
    public function test_every_type_maps_to_json_type(): void
    {
        foreach (FieldType::all() as $type) {
            $json = FieldType::toJsonType($type);
            $this->assertNotSame('', $json, "字段类型 {$type} 缺少 JSON 类型映射");
        }
    }

    public function test_new_field_types_compile(): void
    {
        $resource = new class extends \Aimanong\Resource
        {
            public static function model(): string
            {
                return \stdClass::class;
            }

            public static function uri(): string
            {
                return 'test';
            }

            public static function label(): string
            {
                return '测试';
            }

            public static function form(Form $form): void
            {
                $form->password('pwd')->label('密码');
                $form->tel('phone')->label('电话');
                $form->money('price')->label('价格');
                $form->rate('score')->label('评分')->max(5);
                $form->slider('progress')->label('进度')->range(0, 100);
                $form->multiSelect('tags')->label('标签')->options(['a' => '甲', 'b' => '乙']);
                $form->radio('gender')->label('性别')->options(['m' => '男', 'f' => '女']);
                $form->checkbox('hobbies')->label('爱好')->options(['x' => '阅读']);
                $form->time('open_at')->label('营业时间');
                $form->dateRange('period')->label('周期');
                $form->color('theme')->label('主题色');
                $form->icon('ico')->label('图标');
                $form->tags('labels')->label('标记');
                $form->divider('分组标题');
            }
        };

        $node = (new Compiler)->compile($node = get_class($resource));

        $names = array_map(fn ($f): string => $f->name, $node->fields);

        $this->assertContains('pwd', $names);
        $this->assertContains('price', $names);
        $this->assertContains('tags', $names);
    }

    public function test_column_formatters(): void
    {
        $grid = new Grid;

        $grid->column('enabled', '启用')->bool();
        $grid->column('status', '状态')->badge();
        $grid->column('amount', '金额')->money('$');
        $grid->column('avatar', '头像')->image();
        $grid->column('homepage', '主页')->link('访问');
        $grid->column('progress', '进度')->progress();

        $nodes = $grid->toNodes();

        $byName = [];
        foreach ($nodes as $n) {
            $byName[$n->name] = $n;
        }

        $this->assertSame('bool', $byName['enabled']->formatter);
        $this->assertSame('是', $byName['enabled']->props['trueLabel']);
        $this->assertSame('否', $byName['enabled']->props['falseLabel']);

        $this->assertSame('badge', $byName['status']->formatter);
        $this->assertSame('money', $byName['amount']->formatter);
        $this->assertSame('$', $byName['amount']->props['symbol']);
        $this->assertSame('image', $byName['avatar']->formatter);
        $this->assertSame('link', $byName['homepage']->formatter);
        $this->assertSame('访问', $byName['homepage']->props['text']);
        $this->assertSame('progress', $byName['progress']->formatter);
    }

    public function test_bool_formatter_accepts_custom_labels(): void
    {
        $grid = new Grid;
        $grid->column('published', '发布')->bool('已发布', '未发布');

        $node = $grid->toNodes()[0];

        $this->assertSame('已发布', $node->props['trueLabel']);
        $this->assertSame('未发布', $node->props['falseLabel']);
    }

    public function test_map_with_integer_keys_serializes_as_object(): void
    {
        // 回归：PHP 会把 "0"/"1" 转回整数，导致 JSON 变成数组
        $grid = new Grid;
        $grid->column('resolved', '解决')->map([0 => '未解决', 1 => '已解决']);

        $props = $grid->toNodes()[0]->props;
        $json = json_encode($props['map']);

        $this->assertStringStartsWith('{', (string) $json, 'map 必须序列化为对象而非数组');
        $this->assertStringContainsString('"0"', (string) $json);
    }
}
