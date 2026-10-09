<?php

declare(strict_types=1);

namespace Aimanong\Grid;

use Aimanong\Schema\Ast\ColumnNode;

/**
 * 表格列。
 *
 * AI-First 约定：链式调用扁平化，方法签名唯一，无重载。
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
        $object = new \stdClass();

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
