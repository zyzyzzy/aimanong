<?php

declare(strict_types=1);

namespace Aimanong\Region\Fields;

use Aimanong\Form\Fields\Field;

/**
 * 省市区三级联动字段。
 *
 * 由 aimanong/region 扩展提供 —— 用于验证插件可以注入字段类型，
 * 无需修改框架核心。
 *
 * 用法：
 *   $form->region('area')->label('所在地区');
 *
 * 存储形态：完整路径字符串（如「广东省/深圳市/南山区」）
 * 或编码（通过 ->storeCode() 切换）
 */
class RegionField extends Field
{
    /**
     * 类型名 —— 必须与 Extension::field() 注册的一致。
     */
    protected string $type = 'region';

    /**
     * 层级：2 = 省市，3 = 省市区。
     *
     * ## 为什么要有构造函数
     *
     * 原先只声明了这个属性的默认值 3，**但从不写进 props** ——
     * 于是 Schema 下发的字段定义里压根没有 `level`。
     *
     * 前端模板只能靠 `(f.props.level || 3) === 3` 兜底，
     * 默认值「恰好」是对的。但这种巧合经不起改动：
     * 哪天把默认改成 2，前端 `|| 3` 会把它当成 3，
     * 而后端以为自己在用 2 —— 症状是「区级下拉莫名出现/消失」，
     * 没有任何报错。
     *
     * 声明的东西必须真的下发（宪法 ②「声明唯一」）。
     */
    protected int $level = 3;

    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);

        // 默认层级也要进 props，前端不该猜
        $this->props['level'] = $this->level;
    }

    /**
     * 是否存储编码而非名称。
     */
    protected bool $storeCode = false;

    public function level(int $level): static
    {
        $this->level = max(1, min(3, $level));
        $this->props['level'] = $this->level;

        return $this;
    }

    /**
     * 两联动（只选到市）。
     */
    public function cityLevel(): static
    {
        return $this->level(2);
    }

    /**
     * 存储编码而非名称。
     */
    public function storeCode(bool $value = true): static
    {
        $this->storeCode = $value;
        $this->props['storeCode'] = $value;

        return $this;
    }

    /**
     * 数据源（省份列表，前端据此级联）。
     *
     * 数据随 Schema 下发，前端无需额外请求。
     *
     * @param  array<int, array<string, mixed>>  $tree
     */
    public function data(array $tree): static
    {
        $this->props['data'] = $tree;

        return $this;
    }
}
