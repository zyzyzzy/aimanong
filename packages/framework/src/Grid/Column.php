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
     * @param  array<string, mixed>  $map
     */
    public function map(array $map): static
    {
        $this->formatter = 'map';
        $this->props['map'] = $map;

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
