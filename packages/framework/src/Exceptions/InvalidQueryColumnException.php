<?php

declare(strict_types=1);

namespace Aimanong\Exceptions;

/**
 * 查询列不合法。
 *
 * 场景：声明的列参与了**搜索/排序/筛选**，但它既不是本表真实列，
 * 也不是合法的关联路径（关联不存在，或关联目标表没有该列）。
 *
 * ## 为什么在编译期就拒绝
 *
 * 真实场景验证发现三种失败形态：
 *   1. 关联列直接当列名 → 运行时 500（且会拖垮同一查询的其它条件）
 *   2. 不存在的列       → **静默返回错误结果**（最危险：无报错，结果不对）
 *   3. 非法字符         → 依赖 Eloquent 加引号兜底（不该依赖）
 *
 * 这些都应在**开发阶段**而非生产环境暴露，因此编译期即抛异常。
 */
class InvalidQueryColumnException extends AiReadableException
{
    /**
     * @param  array<int, array<string, mixed>>  $issues
     */
    public function __construct(
        protected string $resource,
        protected array $issues,
    ) {
        parent::__construct($this->buildMessage());
    }

    protected function buildMessage(): string
    {
        $count = count($this->issues);

        return sprintf(
            '%s 有 %d 个列不能用于查询（搜索/排序/筛选）',
            $this->resource,
            $count
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $details = [];

        foreach ($this->issues as $issue) {
            $line = "列 '{$issue['column']}': ".($issue['reason'] ?? '不合法');

            if (! empty($issue['suggestion'])) {
                $line .= "（是否想用 '{$issue['suggestion']}'？）";
            }

            $details[] = $line;
        }

        $first = $this->issues[0] ?? [];

        return [
            'did_you_mean' => $first['suggestion'] ?? null,
            'columns' => array_column($this->issues, 'column'),
            'problems' => $details,
            'hint' => '关联列可以搜索/排序（框架会自动走 whereHas），'
                .'但关联必须在模型上定义，且关联目标表要有该列。'
                .'不存在的列必须改正或去掉 ->searchable() / ->sortable() / ->filter()。',
            'example' => "\$grid->column('product.name', '商品')->searchable();  // 需模型中定义 product() 关联",
            'docs' => 'https://zyzyzzy.github.io/aimanong/api/columns.html',
        ];
    }

    public static function errorCode(): string
    {
        return 'INVALID_QUERY_COLUMN';
    }
}
