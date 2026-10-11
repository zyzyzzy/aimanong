<?php

declare(strict_types=1);

namespace Aimanong\Exceptions;

/**
 * 声明了不存在（或没有可用条目）的数据字典。
 *
 * ## 为什么必须抛异常，而不是给个空下拉框
 *
 * 数据字典最危险的失败模式是**静默**：
 * `->dict('order_stauts')` 拼错一个字母，下拉框就是空的。
 * 页面照常渲染、保存照常成功、测试照常全绿 ——
 * 用户只会觉得「这个框点不动」，而 AI 会以为任务完成。
 *
 * 这与本框架「不提供只看起来能用的能力」的定位直接冲突，
 * 所以在**编译期**就报错，并给出可选字典列表 + 模糊匹配建议。
 */
class DictNotFoundException extends AiReadableException
{
    /**
     * @param  array<int, string>  $available
     */
    public function __construct(
        string $message,
        protected string $dictCode = '',
        protected array $available = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $available = $this->available;
        sort($available);

        return [
            'did_you_mean' => $this->suggest(),
            'dict_code' => $this->dictCode,
            'available_dicts' => $available,
            'hint' => $available === []
                ? '项目里还没有任何数据字典。请在后台「数据字典」里新建，'
                    .'或在 config/aimanong.php 的 foundation.dict.declarations 中声明。'
                : '可用字典: '.implode(', ', array_slice($available, 0, 30)),
            'example' => "\$form->select('status')->dict('"
                .($this->suggest() ?? 'order_status')."');",
            'docs' => 'https://aimanong.com/llms/dict.txt',
        ];
    }

    public static function errorCode(): string
    {
        return 'DICT_NOT_FOUND';
    }

    /**
     * 模糊匹配最接近的字典 code。
     */
    protected function suggest(): ?string
    {
        if ($this->dictCode === '') {
            return null;
        }

        $best = null;
        $shortest = -1;

        foreach ($this->available as $candidate) {
            $lev = levenshtein(strtolower($this->dictCode), strtolower($candidate));

            if ($lev < $shortest || $shortest < 0) {
                $best = $candidate;
                $shortest = $lev;
            }
        }

        return $shortest >= 0 && $shortest <= 4 ? $best : null;
    }
}
