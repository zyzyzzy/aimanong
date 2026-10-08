<?php

declare(strict_types=1);

namespace Aimanong\Exceptions;

/**
 * 声明了不存在的列/字段。
 *
 * AI 最常见的错误之一是把列名拼错（如 customer_nmae）。
 * 原先这类错误被静默吞掉 —— 该列在结果里凭空消失，
 * AI 得不到任何反馈，会以为任务成功。
 *
 * 本异常给出：拼错的列名 + 真实字段列表 + 模糊匹配建议。
 */
class GhostColumnException extends AiReadableException
{
    /**
     * @param  array<int, string>  $ghosts
     * @param  array<int, string>  $available
     */
    public function __construct(
        string $message,
        protected array $ghosts = [],
        protected array $available = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'did_you_mean' => $this->suggest(),
            'ghost_columns' => $this->ghosts,
            'available_columns' => $this->available,
            'hint' => '可用列: '.implode(', ', $this->available),
            'example' => "\$grid->column('".($this->suggest() ?? 'name')."', '...');",
            'docs' => 'https://aimanong.com/llms/grid.txt',
        ];
    }

    public static function errorCode(): string
    {
        return 'GHOST_COLUMN';
    }

    /**
     * 为每个拼错的列名找最接近的真实列。
     */
    protected function suggest(): ?string
    {
        $ghost = $this->ghosts[0] ?? null;

        if ($ghost === null) {
            return null;
        }

        // 去掉 "column:" / "field:" 前缀
        $name = str_contains($ghost, ':') ? explode(':', $ghost, 2)[1] : $ghost;

        $best = null;
        $shortest = -1;

        foreach ($this->available as $real) {
            $lev = levenshtein(strtolower($name), strtolower($real));

            if ($lev < $shortest || $shortest < 0) {
                $best = $real;
                $shortest = $lev;
            }
        }

        // 距离过大则不猜测
        return $shortest >= 0 && $shortest <= 4 ? $best : null;
    }
}
