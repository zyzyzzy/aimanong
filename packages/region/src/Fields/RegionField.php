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
     */
    protected int $level = 3;

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
