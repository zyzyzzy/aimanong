<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Form\Form;
use PHPUnit\Framework\TestCase;

/**
 * 分步表单测试。
 *
 * 设计约束：框架铁律之一是「不嵌套闭包」，
 * 所以步骤用「当前步骤游标」声明，字段归属由顺序决定。
 */
class StepFormTest extends TestCase
{
    protected function steppedForm(): Form
    {
        $form = new Form;

        $form->step('基本信息');
        $form->text('name');
        $form->text('email');

        $form->step('联系方式');
        $form->text('phone');

        return $form;
    }

    public function test_single_page_form_is_not_stepped(): void
    {
        $form = new Form;
        $form->text('name');

        $this->assertFalse($form->isStepped());
        $this->assertSame([], $form->getSteps());
    }

    public function test_step_declaration_enables_stepped_mode(): void
    {
        $form = $this->steppedForm();

        $this->assertTrue($form->isStepped());
        $this->assertSame(['基本信息', '联系方式'], array_keys($form->getSteps()));
    }

    public function test_fields_are_assigned_to_declaration_step(): void
    {
        $steps = $this->steppedForm()->getSteps();

        $this->assertSame(['name', 'email'], $steps['基本信息']);
        $this->assertSame(['phone'], $steps['联系方式']);
    }

    public function test_steps_with_fields_pairs_definitions(): void
    {
        $form = $this->steppedForm();
        $data = $form->toArray();

        $this->assertTrue($data['stepped']);
        $this->assertCount(2, $data['steps']);

        $first = $data['steps'][0];
        $this->assertSame('基本信息', $first['title']);
        $this->assertCount(2, $first['fields']);
        $this->assertSame('name', $first['fields'][0]['name']);

        $second = $data['steps'][1];
        $this->assertSame('联系方式', $second['title']);
        $this->assertCount(1, $second['fields']);
    }

    public function test_validation_rules_cover_all_steps(): void
    {
        $form = new Form;
        $form->step('第一步');
        $form->text('a')->required();
        $form->step('第二步');
        $form->text('b')->required()->max(10);

        $rules = $form->validationRules();

        // 分步不影响校验规则收集 —— 提交时所有步骤一起校验
        $this->assertArrayHasKey('a', $rules);
        $this->assertArrayHasKey('b', $rules);
        $this->assertContains('max:10', $rules['b']);
    }

    public function test_step_title_returns_chainable_static(): void
    {
        $form = new Form;
        $result = $form->step('步骤A');

        $this->assertSame($form, $result, 'step() 必须返回 $this 以支持链式');
    }
}
