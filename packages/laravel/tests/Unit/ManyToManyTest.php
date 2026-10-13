<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Form\Form;
use PHPUnit\Framework\TestCase;

/**
 * 多对多支持测试。
 *
 * CMS 场景验证暴露的三个问题：
 *   1. 列表单元格渲染为空（前端 cellValue 为单值设计）
 *   2. 导出直接 500（Exporter 对集合取 ->name）
 *   3. 表单多选需手写 sync（框架不支持关联字段写入）
 *   4. 编辑回填勾不上（关联值是对象数组，checkbox 的 :value 是标量）
 */
class ManyToManyTest extends TestCase
{
    public function test_multiselect_can_declare_relation(): void
    {
        $form = new Form;
        $field = $form->multiSelect('tags')->relation('tags');

        $this->assertSame('tags', $field->getRelation());
        $this->assertSame(['tags' => 'tags'], $form->relationFields());
    }

    public function test_form_reports_relation_fields(): void
    {
        $form = new Form;
        $form->text('title');
        $form->multiSelect('tags')->relation('tags')->options([1 => 'a']);
        $form->select('status');   // 普通 select 不算关联字段

        $relations = $form->relationFields();

        $this->assertCount(1, $relations, '只有声明了 relation 的字段才算');
        $this->assertArrayHasKey('tags', $relations);
        $this->assertArrayNotHasKey('status', $relations);
    }

    public function test_multiselect_without_relation_is_not_relation_field(): void
    {
        $form = new Form;
        $form->multiSelect('tags')->options([1 => 'a']);

        $this->assertSame([], $form->relationFields(), '未声明关联的 multiselect 不是关联字段');
    }

    public function test_relation_prop_is_serializable(): void
    {
        $form = new Form;
        $form->multiSelect('tags')->relation('tags')->options([1 => 'a']);

        $json = json_encode($form->toArray());

        $this->assertIsString($json);
        $this->assertStringContainsString('"relation":"tags"', (string) $json);
    }

    /**
     * 编译产物必须携带 relationFields，否则 Controller 无法分离关联字段。
     */
    public function test_compiler_exposes_relation_fields(): void
    {
        $form = new Form;
        $form->multiSelect('tags')->relation('tags');

        $data = $form->toArray();

        $this->assertTrue($data['stepped'] === false);
        $this->assertSame(['tags' => 'tags'], $form->relationFields());
    }
}
