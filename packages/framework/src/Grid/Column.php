<?php

declare(strict_types=1);

namespace Aimanong\Grid;

use Aimanong\Schema\Ast\ColumnNode;

/**
 * 表格列。
 *
 * Aimanong 约定：链式调用扁平化，方法签名唯一，无重载。
 */
class Column
{
    protected string $label;

    protected bool $sortable = false;

    protected bool $searchable = false;

    protected bool $filterable = false;

    protected ?string $formatter = null;

    protected ?string $enumClass = null;

    /**
     * @var array<string, mixed>
     */
    protected array $props = [];

    public function __construct(
        protected string $name,
        ?string $label = null,
    ) {
        $this->label = $label ?? $name;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function sortable(bool $value = true): static
    {
        $this->sortable = $value;

        return $this;
    }

    public function searchable(bool $value = true): static
    {
        $this->searchable = $value;

        return $this;
    }

    public function filter(bool $value = true): static
    {
        $this->filterable = $value;

        return $this;
    }

    /**
     * 日期时间格式化。
     */
    public function dateTime(string $format = 'Y-m-d H:i:s'): static
    {
        $this->formatter = 'datetime';
        $this->props['format'] = $format;

        return $this;
    }

    /**
     * 枚举值 → 标签映射。
     *
     * @param  class-string  $enumClass
     */
    public function using(string $enumClass): static
    {
        $this->enumClass = $enumClass;
        $this->formatter = 'enum';

        return $this;
    }

    /**
     * 值 → 标签映射。
     *
     * 存储为 stdClass 而非数组：PHP 会把数字字符串键（"0"/"1"）
     * 自动转回整数，导致 json_encode 序列化成数组 ["未解决","已解决"]
     * 而非对象 {"0":"未解决","1":"已解决"}，前端 map[key] 取值失败。
     * stdClass 可强制保持 JSON 对象形态。
     *
     * @param  array<int|string, mixed>  $map
     */
    public function map(array $map): static
    {
        $object = new \stdClass;

        foreach ($map as $key => $label) {
            $object->{(string) $key} = $label;
        }

        $this->formatter = 'map';
        $this->props['map'] = $object;

        return $this;
    }

    public function width(int $pixels): static
    {
        $this->props['width'] = $pixels;

        return $this;
    }

    /**
     * 布尔列：渲染为「是 / 否」标签，而非 true / false。
     *
     * AI 实测反馈：数据库布尔列默认显示原始 true/false 不直观。
     */
    public function bool(string $trueLabel = '是', string $falseLabel = '否'): static
    {
        $this->formatter = 'bool';
        $this->props['trueLabel'] = $trueLabel;
        $this->props['falseLabel'] = $falseLabel;

        return $this;
    }

    /**
     * 徽章样式展示。
     */
    public function badge(): static
    {
        $this->formatter = 'badge';

        return $this;
    }

    /**
     * 图片展示。
     */
    public function image(int $height = 32): static
    {
        $this->formatter = 'image';
        $this->props['height'] = $height;

        return $this;
    }

    /**
     * 超链接展示。
     */
    public function link(?string $text = null): static
    {
        $this->formatter = 'link';

        if ($text !== null) {
            $this->props['text'] = $text;
        }

        return $this;
    }

    /**
     * 进度条展示（用于百分比 / 完成度）。
     */
    public function progress(): static
    {
        $this->formatter = 'progress';

        return $this;
    }

    /**
     * 金额展示：自动千分位与两位小数。
     */
    public function money(string $symbol = '¥'): static
    {
        $this->formatter = 'money';
        $this->props['symbol'] = $symbol;

        return $this;
    }

    public function toNode(): ColumnNode
    {
        return new ColumnNode(
            name: $this->name,
            label: $this->label,
            sortable: $this->sortable,
            searchable: $this->searchable,
            filterable: $this->filterable,
            formatter: $this->formatter,
            enumClass: $this->enumClass,
            props: $this->props,
        );
    }
}
