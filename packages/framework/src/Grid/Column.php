<?php

declare(strict_types=1);

namespace Aimanong\Grid;

use Aimanong\Exceptions\DictNotFoundException;
use Aimanong\Foundation\Dict\Dictionary;
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

    /**
     * 附件列：把存储路径渲染成「文件名」下载链接。
     *
     * 不这么做的话列表里显示的是一串 `uploads/2026/10/xxxx.pdf` ——
     * 对用户毫无意义，还得自己拼路径才能下载。
     */
    public function file(): static
    {
        $this->formatter = 'file';

        return $this;
    }

    /**
     * 用数据字典渲染这一列：自动取「值 → 文案」映射与语义色。
     *
     * ```php
     * $grid->column('status', '状态')->dict('order_status');
     * ```
     *
     * 为什么要有它：没有它，同一个枚举会在列表页写一遍 map()、
     * 表单页写一遍 options() —— 改一个文案要改 N 处，
     * 而 AI 也无从判断两处是否一致（本项目的头号错误来源）。
     *
     * @throws DictNotFoundException 字典不存在
     */
    public function dict(string $code): static
    {
        $dictionary = new Dictionary;

        if (! $dictionary->has($code)) {
            $available = array_keys($dictionary->all());

            throw new DictNotFoundException(
                "数据字典 [{$code}] 不存在（或没有任何启用中的条目）。",
                $code,
                array_map('strval', $available)
            );
        }

        $this->map($dictionary->map($code));
        $this->badge();

        $this->props['dict'] = $code;

        $colors = $dictionary->colors($code);

        if ($colors !== []) {
            $this->props['dictColors'] = $colors;
        }

        return $this;
    }

    /**
     * 条件样式：值满足条件时高亮。
     *
     * 真实场景需求：库存少于 10 要标红。
     *
     * 用法：$grid->column('stock', '库存')->dangerWhen(fn ($v) => $v < 10);
     *
     * 注意：闭包只在**编译期**用于生成判定规则，不会被序列化 ——
     * 因此传的是「比较表达式」而非任意回调，保证 AI 可读、可序列化。
     *
     * @param  string  $operator  支持 < <= > >= == != 与 between
     */
    public function dangerWhen(string $operator, mixed $value, string $level = 'danger'): static
    {
        $this->props['highlight'] = [
            'operator' => $operator,
            'value' => $value,
            'level' => $level,
        ];

        return $this;
    }

    /**
     * 条件样式的语义化快捷方法：小于阈值时高亮。
     */
    public function dangerBelow(int|float $threshold, string $level = 'danger'): static
    {
        return $this->dangerWhen('<', $threshold, $level);
    }

    /**
     * 条件样式的语义化快捷方法：大于阈值时高亮。
     */
    public function warningAbove(int|float $threshold, string $level = 'warning'): static
    {
        return $this->dangerWhen('>', $threshold, $level);
    }

    /**
     * 关联列：显示关联模型的字段而非外键 ID。
     *
     * 真实场景需求：商品列表显示分类名，而不是 category_id。
     *
     * 用法：$grid->column('category.name', '分类');
     *
     * 点号路径会在查询时通过 Eloquent 的 with() 预加载，
     * 避免 N+1 问题。
     */
    public function relation(string $path): static
    {
        $this->props['relation'] = $path;

        return $this;
    }

    /**
     * 关联列的简写：列名本身用点号表达关联路径时自动识别。
     *
     * 例：$grid->column('category.name', '分类') —— 构造函数里
     * 检测到点号即自动设置关联，无需显式调用本方法。
     */
    public function isRelation(): bool
    {
        return isset($this->props['relation']) || str_contains($this->name, '.');
    }

    /**
     * 关联路径（如 category.name）。
     */
    public function relationPath(): ?string
    {
        if (isset($this->props['relation'])) {
            return (string) $this->props['relation'];
        }

        return str_contains($this->name, '.') ? $this->name : null;
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
