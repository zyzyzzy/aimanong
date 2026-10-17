<?php

declare(strict_types=1);

namespace Aimanong\Layout;

use InvalidArgumentException;

/**
 * 详情页的**结构声明** —— 把字段分组。
 *
 * ## 为什么需要它
 *
 * `$show->fields(['a','b','c', ...])` 是一长串平铺。字段一多就有两个问题：
 *
 *   1. **读不出来**：18 个字段一字排开，看不出哪些是一类
 *   2. **页面没法组织**：想分成「基本信息 / 联系方式 / 财务」三块，
 *      此前只能去写 Blade —— 也就是「AI 必须关心前端实现细节」
 *
 * 这个类就是给详情页一个**结构词表**：分块、分标签页。
 *
 * ## 用法
 *
 * ```php
 * public static function layout(Layout $layout): void
 * {
 *     $layout->tabs([
 *         '基本' => ['name', 'level', 'industry'],
 *         '联系' => ['phone', 'website', 'address'],
 *         '财务' => ['annual_revenue', 'employee_count'],
 *     ]);
 * }
 * ```
 *
 * ## 与 show() 的关系
 *
 * `show()` 声明**有哪些字段**，`layout()` 声明**怎么摆**。
 * 两者都声明时：layout 里出现的按分组渲染，`show()` 里没进 layout 的
 * 字段**仍然会出现**（追加到最后一组）—— 否则加了个 layout 就会
 * 静默吞掉字段，那是"配了反而更少"。
 *
 * ## 只做三种容器，不是 85 个
 *
 * amis 有 85 个组件。我们只取**结构**这一个维度，且只取最常见的三种，
 * 因为每多一种容器就多一个要维护、要对拍的渲染分支
 *（见 internal-docs/amis借鉴分析-方案.md 的「词表预算」）。
 */
class Layout
{
    /**
     * 容器种类。
     *
     *   tabs     标签页 —— 组之间切换，适合字段多且有明显分类
     *   sections 分块 —— 全部展开、带小标题，适合字段不多（默认）
     *   cards    卡片网格 —— 适合短字段概览
     */
    public const KINDS = ['sections', 'tabs', 'cards'];

    protected string $kind = 'sections';

    /**
     * @var array<int, array{title: string, fields: array<int, string>}>
     */
    protected array $groups = [];

    /**
     * 按标签页分组。
     *
     * @param  array<string, array<int, string>>  $groups  组名 => 字段名数组
     */
    public function tabs(array $groups): static
    {
        return $this->fill('tabs', $groups);
    }

    /**
     * 按区块分组（全部展开，带小标题）。
     *
     * @param  array<string, array<int, string>>  $groups
     */
    public function sections(array $groups): static
    {
        return $this->fill('sections', $groups);
    }

    /**
     * 按卡片网格分组。
     *
     * @param  array<string, array<int, string>>  $groups
     */
    public function cards(array $groups): static
    {
        return $this->fill('cards', $groups);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @return array<int, array{title: string, fields: array<int, string>}>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * 是否声明过结构。
     *
     * 未声明时前端按**平铺**渲染详情字段（= 老行为），
     * 而不是渲染一个空页面。
     */
    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    /**
     * @param  array<string, array<int, string>>  $groups
     */
    protected function fill(string $kind, array $groups): static
    {
        if ($groups === []) {
            throw new InvalidArgumentException(
                "layout()->{$kind}([]) 传了空数组 —— 没有任何字段的「分组」多半是笔误。"
                .'确实想清空请直接不声明 layout()。'
            );
        }

        foreach ($groups as $title => $fields) {
            // PHPStan 按 @param 已知道 $fields 是数组，所以这里只查空
            if ($fields === []) {
                throw new InvalidArgumentException(
                    "layout 分组「{$title}」没有字段。空分组会在页面上渲染成一个空标题。"
                );
            }
        }

        $this->kind = $kind;
        $this->groups = [];

        foreach ($groups as $title => $fields) {
            $this->groups[] = [
                'title' => (string) $title,
                'fields' => array_values(array_map('strval', $fields)),
            ];
        }

        return $this;
    }

    /**
     * 结构 → schema 产物。
     *
     * @param  array<int, string>  $knownFields  该 Resource 声明过的详情字段名
     * @return array<string, mixed>
     */
    public function toArray(array $knownFields = []): array
    {
        if ($this->isEmpty()) {
            return ['kind' => 'flat', 'groups' => []];
        }

        /*
         * ⚠️ 未知字段必须报错，不能静默丢掉。
         *
         * 「在 layout 里写了但字段不存在」的后果是**页面上少一块内容**
         * 而没有任何提示 —— 那正是本项目最反对的那类问题。
         * 编译期就报出来，顺便给出可用字段。
         */
        if ($knownFields !== []) {
            $unknown = [];

            foreach ($this->groups as $group) {
                foreach ($group['fields'] as $name) {
                    if (! in_array($name, $knownFields, true)) {
                        $unknown[] = "{$name}（在分组「{$group['title']}」里）";
                    }
                }
            }

            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    'layout 引用了未在 show() 中声明的字段：'.implode('、', $unknown)
                    .'。可用的字段：'.implode(', ', $knownFields)
                );
            }

            /*
             * `show()` 里声明了、但没进任何分组的字段 → 追加到最后一组。
             *
             * 不这么做的话，加一个 layout() 会**静默吞掉**字段 ——
             * 使用者会以为「我明明声明了怎么不显示」。
             */
            $placed = [];
            foreach ($this->groups as $group) {
                $placed = array_merge($placed, $group['fields']);
            }

            $leftover = array_values(array_diff($knownFields, $placed));

            if ($leftover !== []) {
                $this->groups[count($this->groups) - 1]['fields'] = array_merge(
                    $this->groups[count($this->groups) - 1]['fields'],
                    $leftover
                );
            }
        }

        return [
            'kind' => $this->kind,
            'groups' => $this->groups,
        ];
    }
}
