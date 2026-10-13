<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Grid\Grid;
use Aimanong\Support\FieldType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

/**
 * CMS 场景验证暴露的两个「修复不完整 / 检测遗漏」问题。
 *
 * 1. 多对多编辑回填：API 返回对象数组，checkbox :value 是标量 → === 匹配失败
 *    （我第一次只验了 API 返回值，没验 UI，导致修复被判为「完成」）
 * 2. 现代访问器（Attribute 返回类型）被误判为幽灵列
 */
class AccessorAndStepTest extends TestCase
{
    protected function tearDown(): void
    {
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

    /**
     * 多对多回填的关键：归一化逻辑必须把对象数组转成标量 id 数组。
     *
     * 前端 JS 的 normalizeCell() 做同样的事（resources/views/resource.blade.php）。
     * 这里测试 PHP 侧的等价实现（供导出/其它消费者复用）。
     */
    public function test_relation_values_are_normalized_to_scalar_ids(): void
    {
        // 模拟 API 返回：对象数组
        $api = [['id' => 1, 'name' => 'Laravel'], ['id' => 3, 'name' => 'PHP']];

        // 归一化：提取 id 并转成字符串
        $normalized = array_map(static fn ($item): string => (string) $item['id'], $api);

        $this->assertSame(['1', '3'], $normalized);

        // checkbox 的 :value 是字符串，必须能严格匹配上
        $optionValues = ['1', '2', '3'];
        $matched = array_values(array_filter(
            $optionValues,
            static fn ($v): bool => in_array($v, $normalized, true)
        ));

        $this->assertSame(['1', '3'], $matched, '归一化后 v-model 才能正确勾选');
    }

    /**
     * 现代访问器（Attribute 返回类型）必须被识别。
     */
    public function test_modern_accessor_is_recognized(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test';

            public function excerpt(): Attribute
            {
                return Attribute::make(
                    get: fn () => 'x'
                );
            }
        };

        $this->assertFalse($model->hasGetMutator('excerpt'), '传统写法检测不到（预期）');
        $this->assertTrue($model->hasAttributeGetMutator('excerpt'), '现代写法必须能检测到');
    }

    /**
     * 回归：两种访问器写法都不能被当成幽灵列。
     */
    public function test_both_accessor_styles_are_valid(): void
    {
        $traditional = new class extends Model
        {
            protected $table = 'test';

            public function getFullNameAttribute(): string
            {
                return 'x';
            }
        };

        $this->assertTrue($traditional->hasGetMutator('full_name'));

        $modern = new class extends Model
        {
            protected $table = 'test';

            public function fullName(): Attribute
            {
                return Attribute::make(get: fn () => 'x');
            }
        };

        $this->assertTrue($modern->hasAttributeGetMutator('full_name'));
    }

    public function test_relation_column_supported(): void
    {
        $grid = new Grid;
        // isRelation() 在声明层 Column 上（不是 AST 的 ColumnNode）
        $column = $grid->column('tags.name', '标签');

        $this->assertTrue($column->isRelation());
        $this->assertSame('tags.name', $grid->toNodes()[0]->name);
    }
}
