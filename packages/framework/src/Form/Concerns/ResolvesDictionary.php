<?php

declare(strict_types=1);

namespace Aimanong\Form\Concerns;

use Aimanong\Exceptions\DictNotFoundException;
use Aimanong\Foundation\Dict\Dictionary;

/**
 * 给选项类字段（select / multiselect / radio / checkbox）加上
 * 「从数据字典取选项」的能力。
 *
 * ```php
 * $form->select('status')->dict('order_status');
 * ```
 *
 * ## 现在是编译期展开，不是运行期拉取
 *
 * Schema 每次打开页面都会重新生成，所以字典改完刷新即生效，
 * 前端不需要额外的请求，也不需要处理「选项加载中」状态。
 *
 * ## 字典为空 = 抛异常，不是给空下拉框
 *
 * 拼错 code 的后果是「一个永远选不了值的必填框」，
 * 用户和 AI 都拿不到任何反馈。宁可当场报错。
 */
trait ResolvesDictionary
{
    /**
     * 用数据字典填充选项。
     *
     * @throws DictNotFoundException 字典不存在或没有任何启用中的条目
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

        $this->props['dict'] = $code;

        /*
         * 覆盖 options 而不是合并。
         *
         * 同时写 ->options() 和 ->dict() 属于声明冲突 ——
         * 后者胜出，且 props.dict 会记录来源，
         * AI 自省时能看出这个字段的选项来自字典。
         */
        $this->props['options'] = $dictionary->options($code);

        return $this;
    }
}
