<?php

declare(strict_types=1);

namespace Aimanong\Grid;

use Aimanong\Support\SuggestsMethods;
use Aimanong\Exceptions\DictNotFoundException;
use Aimanong\Schema\Ast\ColumnNode;
use Aimanong\Support\Drivers;

/**
 * 表格列。
 *
 * Aimanong 约定：链式调用扁平化，方法签名唯一，无重载。
 */
class Column
{
    use SuggestsMethods;

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
        $dictionary = Drivers::dictionary();

        if (! $dictionary->has($code)) {
            $available = $dictionary->codes();

            throw new DictNotFoundException(
                "数据字典 [{$code}] 不存在（或没有任何启用中的条目）。",
                $code,
                array_map('strval', $available)
            );
        }

        /*
         * ⚠️ 顺序不能反：必须「先 badge 再 map」。
         *
         * badge() 会把 formatter 覆盖成 'badge'，而导出器只在
         * formatter 为 map / enum 时才套用中文映射 ——
         * 于是界面上是「商务谈判」，导出的 CSV 里却是 negotiation。
         * 前端三种 formatter 都走 mapLabel，所以**只有导出会坏**，
         * 界面完全看不出来（真实场景验证：运营拿导出文件汇报时发现）。
         *
         * 正确顺序下 formatter 最终是 'map'，前端照样渲染成徽章。
         */
        $this->badge();

        /*
         * 值 => 标签、值 => 颜色都**从 options() 自己派生**，
         * 不调驱动上的 map()/colors()。
         *
         * 为什么：契约 `DictionarySource` 只有 has/codes/options 三个方法。
         * 原先这里直接调 `$dictionary->map()` / `->colors()` —— 那是
         * **契约外的方法**，Laravel 的 Dictionary 恰好有，所以一直没暴露；
         * 换成 ThinkPHP 实现（只按契约写）就当场 `Call to undefined method`。
         *
         * 这类「契约不完整」是最隐蔽的一种隐式耦合：单框架下永远正确，
         * 换实现时立刻崩，而崩的位置离原因很远。
         *
         * 派生结果与 Laravel `Dictionary::map()/colors()` 逐字等价
         * （两边都是「遍历 options()，非空 color 才收录」）。
         */
        $options = $dictionary->options($code);
        $map = [];
        $colors = [];

        foreach ($options as $option) {
            // 契约保证 value/label 一定存在且是 string —— 不需要 ?? 兜底
            $value = $option['value'];

            if ($value === '') {
                continue;
            }

            $map[$value] = $option['label'];

            $color = $option['color'];

            if ($color !== null && $color !== '') {
                $colors[$value] = $color;
            }
        }

        $this->map($map);

        $this->props['dict'] = $code;

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
            props: $this->resolveEnumLabels(),
        );
    }

    /**
     * 把枚举的**标签**解析进 `props.map`。
     *
     * ## 为什么必须在这里做
     *
     * `->using(Enum::class)` 原先只往 schema 里写了一个
     * `formatter: enum` + `enum: App\Enums\UserStatus`（PHP 类名的**字符串**）。
     * 而前端是 JS —— 它解析不了 PHP 类名，只会去读 `props.map` 取标签。
     *
     * 后果（实测）：声明合法、`ai:verify` 通过、schema 也生成了，
     * **但列表照旧显示 `active`/`disabled` 原始值**。
     * 一个 LLM 为此做了对照实验（给枚举加 label()/getLabel()/title()/labels()
     * 逐个试），确认框架「一个都不调用」，只能改用 `->map()` 绕开。
     *
     * 而 `llms.txt` 第 154 行明写着 `->using(EnumClass::class)  // 值→标签映射`
     * —— 文档承诺了，实现没做。这是本项目最反对的那类「声明了却不生效」。
     *
     * ## 标签从哪来
     *
     * 依次尝试，都取不到就退回**枚举 case 名**（`Active` 比 `active` 可读）：
     *   ① `label()`   —— 最常见
     *   ② `getLabel()` —— Filament 系习惯
     *
     * 已显式 `->map([...])` 时不覆盖 —— 手写的优先。
     *
     * @return array<string, mixed>
     */
    protected function resolveEnumLabels(): array
    {
        if ($this->enumClass === null || ! enum_exists($this->enumClass)) {
            return $this->props;
        }

        /*
         * 手写了 map 就不动它。
         *
         * ⚠️ 守卫要同时认 **stdClass** —— `map()` 存的是 stdClass 而不是
         * 数组（为了让 JSON 序列化成 `{}` 而不是 `[]`）。第一版只判
         * `is_array`，于是手写的 map 被枚举标签覆盖掉，而且不报错。
         */
        $existing = $this->props['map'] ?? null;

        if ((is_array($existing) && $existing !== []) || $existing instanceof \stdClass) {
            return $this->props;
        }

        $map = new \stdClass;

        foreach ($this->enumClass::cases() as $case) {
            $key = (string) ($case instanceof \BackedEnum ? $case->value : $case->name);
            $map->{$key} = self::labelOf($case);
        }

        $this->props['map'] = $map;

        return $this->props;
    }

    /** 取一个枚举 case 的显示标签 */
    protected static function labelOf(\UnitEnum $case): string
    {
        foreach (['label', 'getLabel'] as $method) {
            if (method_exists($case, $method)) {
                $value = $case->{$method}();

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $case->name;
    }
}
