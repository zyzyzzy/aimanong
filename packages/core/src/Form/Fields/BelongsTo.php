<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

/**
 * 外键下拉 —— 候选自动来自关联模型。
 *
 * ```php
 * // 最简：关联名从字段名推断（author_id → author），显示列自动挑
 * $form->belongsTo('author_id', '作者');
 *
 * // 显式指定
 * $form->belongsTo('author_id', '作者')
 *     ->relation('author')
 *     ->labelColumn('name');
 * ```
 *
 * ## 它替声明方省掉的（都是实测中真的踩过的）
 *
 * | 原先要手写 | 现在 |
 * |---|---|
 * | `Author::pluck('name','id')->all()` | 自动查（**忘了 `->all()` 会编译失败**） |
 * | `->rules('exists:authors,id')` | 自动加（**忘了就能提交任意 id**） |
 * | 在模型上定义 `author()` 关联 | 仍然需要 —— 这是模型层的事，框架不代劳 |
 *
 * 候选是**编译期快照**，与 `->options()` 的语义一致；候选集很大时
 * 应改用远程搜索（本框架暂未提供）。
 */
class BelongsTo extends Select
{
    /*
     * ⚠️ schema 里的 type 是 **select**，不是 belongsTo。
     *
     * `type` 表示**控件**是什么（前端按它选渲染分支：模板只认
     * `f.type === 'select'` 才画下拉）。belongsTo 不是一种新控件 ——
     * 它就是「候选由关联解析出来」的 select。
     *
     * 写成 `belongsTo` 的后果是它掉进 `<input>` 分支，
     * 变成一个人手填 id 的文本框 —— 与这个字段存在的意义正好相反。
     * 「它是关联」这件事由 `props.relation` 表达。
     */
    protected string $type = 'select';

    /** 关联方法名；null = 从字段名推断（去掉 `_id` 后缀） */
    protected ?string $relationName = null;

    protected ?string $labelColumn = null;

    public function relation(string $name): static
    {
        $this->relationName = $name;

        return $this;
    }

    public function labelColumn(string $column): static
    {
        $this->labelColumn = $column;

        return $this;
    }

    public function getRelationName(): ?string
    {
        return $this->relationName;
    }

    public function getLabelColumn(): ?string
    {
        return $this->labelColumn;
    }

    /**
     * 推断关联方法名。
     *
     * 约定与 Eloquent/ThinkORM 一致：`author_id` → `author`。
     * 不带 `_id` 时原样返回（允许把字段名直接写成关联名）。
     */
    public function resolvedRelationName(): string
    {
        if ($this->relationName !== null && $this->relationName !== '') {
            return $this->relationName;
        }

        $name = $this->getName();

        return str_ends_with($name, '_id') ? substr($name, 0, -3) : $name;
    }

    /**
     * 把解析结果灌进 props。
     *
     * @param  array<int, array{value: mixed, label: string}>  $options
     */
    public function applyResolved(array $options, string $existsRule): static
    {
        $this->props['options'] = $options;
        $this->props['relation'] = $this->resolvedRelationName();

        /*
         * ⚠️ 非必填时要补 `nullable`，否则**可空外键永远存不了 null**。
         *
         * 实测（L3-4 复测的 agent 抓到）：树形的顶级节点 parent_id 必须为 NULL，
         * 而自动生成的规则是 `exists:departments,id` —— 提交 NULL 直接 422
         * 「The selected parent id is invalid.」。
         *
         * 而且 `ai:verify` 全程绿灯：**声明完全合法，只有真发请求才暴露**。
         *
         * 判据用「有没有声明 required」而不是「数据库列可不可空」——
         * 前者是声明层的意图，后者要驱动参与；而两者的差异只在这种
         * 「可选外键」的常见情形下才出现。
         */
        $rules = $this->rules;

        if (! in_array('required', $rules, true) && ! in_array('nullable', $rules, true)) {
            $rules[] = 'nullable';
        }

        if (! in_array($existsRule, $rules, true)) {
            $rules[] = $existsRule;
        }

        $this->rules = $rules;

        return $this;
    }
}
