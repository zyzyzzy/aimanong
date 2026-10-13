<?php

declare(strict_types=1);

namespace Aimanong\Form\Concerns;

use Aimanong\Form\Fields\Field;

/**
 * 分步表单支持。
 *
 * 设计约束：框架铁律之一是「不嵌套闭包」——AI 生成嵌套闭包错误率高。
 * 所以步骤声明不用 $form->step('基本信息', function () { ... })，
 * 而是用「当前步骤」游标：
 *
 *     $form->step('基本信息');
 *     $form->text('name')->required();      // 属于「基本信息」
 *     $form->step('联系方式');
 *     $form->email('email');                // 属于「联系方式」
 *
 * 字段归属由声明顺序决定，扁平、无嵌套。
 */
trait HasSteps
{
    /**
     * 步骤定义：步骤名 => 字段名列表。
     *
     * @var array<string, array<int, string>>
     */
    protected array $steps = [];

    /**
     * 当前步骤名（null = 单页表单）。
     */
    protected ?string $currentStep = null;

    /**
     * 是否启用分步模式。
     */
    protected bool $stepped = false;

    /**
     * 声明一个新步骤，后续字段都归属该步骤。
     *
     * 用法：$form->step('基本信息');
     */
    public function step(string $title): static
    {
        $this->stepped = true;
        $this->currentStep = $title;

        if (! isset($this->steps[$title])) {
            $this->steps[$title] = [];
        }

        return $this;
    }

    /**
     * 步骤标题别名（更贴近部分 AI 的用词习惯）。
     */
    public function stepTitle(string $title): static
    {
        return $this->step($title);
    }

    public function isStepped(): bool
    {
        return $this->stepped;
    }

    /**
     * 把字段登记到当前步骤。
     *
     * 由 Form::add() 在每次添加字段后调用。
     */
    protected function registerToCurrentStep(Field $field): void
    {
        if ($this->currentStep === null) {
            return;
        }

        $this->steps[$this->currentStep][] = $field->getName();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * 分步模式下按步骤切分字段。
     *
     * @param  array<int, array<string, mixed>>  $fields  已编译的字段定义
     * @return array<int, array<string, mixed>>
     */
    public function stepsWithFields(array $fields): array
    {
        if (! $this->stepped) {
            return [];
        }

        $byName = [];
        foreach ($fields as $f) {
            $byName[$f['name']] = $f;
        }

        $out = [];

        foreach ($this->steps as $title => $names) {
            $stepFields = [];

            foreach ($names as $name) {
                if (isset($byName[$name])) {
                    $stepFields[] = $byName[$name];
                }
            }

            $out[] = [
                'title' => $title,
                'fields' => $stepFields,
            ];
        }

        return $out;
    }
}
