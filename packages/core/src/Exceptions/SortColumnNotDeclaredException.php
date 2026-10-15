<?php

declare(strict_types=1);

namespace Aimanong\Exceptions;

/**
 * `?sort=` 指向了一个不存在的列。
 *
 * ## 为什么是硬错误，而不是 warning
 *
 * 原先 `sort` 直接透传给 `orderBy()`。列不存在时的行为**取决于数据库**：
 *
 *     SQLite：静默接受 `ORDER BY bogus`，返回未排序数据 → 200
 *     MySQL ：报错 → 500
 *
 * SQLite 那种更糟：调用方拿到 **200 + 一坨看起来正常的数据**，
 * 会以为自己要的排序生效了。
 *
 * 实测（L1/L2 十五题）中两个 agent 分别踩到并明确报上来：
 * 「非法 sort 列被静默忽略，**无法靠报错确认参数被接受**」——
 * 它们最后只能改去看 schema 输出。
 *
 * 这违反宪法 ③「零隐式」（优先级高于 ② 和 ①）：
 * 请求做不到就**说做不到**，不要给一个别的顺序冒充。
 *
 * ## 范围
 *
 * 只拦「列根本不存在」。声明过的列即使没标 `sortable` 也照旧放行 ——
 * 那种情况下 `orderBy` **确实生效了**，不算欺骗。
 */
class SortColumnNotDeclaredException extends AiReadableException
{
    /**
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $known
     */
    public function __construct(
        protected string $uri,
        protected string $sort,
        protected array $sortable = [],
        protected array $known = [],
    ) {
        parent::__construct($this->buildMessage());
    }

    protected function buildMessage(): string
    {
        return sprintf(
            'Resource [%s] 没有可排序的列 [%s]。',
            $this->uri,
            $this->sort
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'resource' => $this->uri,
            'sort' => $this->sort,
            'did_you_mean' => $this->suggest(),
            'sortable_columns' => $this->sortable,
            'known_columns' => $this->known,
            'hint' => $this->sortable === []
                ? '该 Resource 还没有任何列标记为可排序 —— '
                    .'在 grid() 里给列加 ->sortable() 即可。'
                : '可排序的列: '.implode(', ', $this->sortable),
        ];
    }

    /**
     * 模糊匹配一个最接近的列名 —— 拼错时直接给出正确写法。
     */
    protected function suggest(): ?string
    {
        $best = null;
        $bestScore = 0.0;

        foreach (array_merge($this->sortable, $this->known) as $candidate) {
            $percent = 0.0;

            similar_text($this->sort, $candidate, $percent);

            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $candidate;
            }
        }

        // 太不像就不猜（猜错比不猜更误导）
        return $bestScore >= 60.0 ? $best : null;
    }

    public static function errorCode(): string
    {
        return 'SORT_COLUMN_NOT_DECLARED';
    }
}
