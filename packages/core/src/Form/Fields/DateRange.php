<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

/**
 * 日期区间字段。
 *
 * ## 存储契约（真实场景验证时补上的）
 *
 * 它天然对应**两个**数据库列，例如合同的有效期是 start_at / end_at。
 * 最初的实现把前端硬编码成绑定 `{字段名}_start` / `{字段名}_end` ——
 * 于是 `$form->dateRange('period')` 会去找 `period_start` / `period_end`，
 * 而真实表里根本没有这两列：表单能渲染、提交永远存不进去，
 * `ai:verify` 还会报一个看似莫名其妙的 SUSPECT_FIELD('period')。
 *
 * 现在必须显式声明两列：
 *
 * ```php
 * $form->dateRange('period')->columns('start_at', 'end_at');
 * ```
 *
 * 未声明时退化为 `{字段名}_start` / `{字段名}_end`（老行为，保持兼容），
 * 但 `ai:verify` 会据此检查真实列是否存在。
 */
class DateRange extends Field
{
    protected string $type = 'daterange';

    /**
     * 指定两个真实列名。
     */
    public function columns(string $start, string $end): static
    {
        $this->props['startColumn'] = $start;
        $this->props['endColumn'] = $end;

        return $this;
    }

    /**
     * 起始列名（未声明时用约定名）。
     */
    public function startColumn(): string
    {
        $value = $this->props['startColumn'] ?? null;

        return is_string($value) && $value !== '' ? $value : $this->name.'_start';
    }

    /**
     * 结束列名。
     */
    public function endColumn(): string
    {
        $value = $this->props['endColumn'] ?? null;

        return is_string($value) && $value !== '' ? $value : $this->name.'_end';
    }
}
