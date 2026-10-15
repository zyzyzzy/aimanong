<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

class Email extends Field
{
    protected string $type = 'email';

    /**
     * 邮箱字段**自动**带上 email 校验规则。
     *
     * ## 为什么这是对的
     *
     * 字段名叫 `email`，用户心智模型里「邮箱字段」=「要校验是邮箱」。
     * 不自动带的话，每个调用方都得记得额外写 `->rules('email')` ——
     * 实测（L1/L2 复测）一个 agent 就漏写了，提交非法邮箱能过。
     *
     * 宪法 ③「零隐式」的反面不是「什么都要显式写」，
     * 而是「声明该蕴含的语义就该蕴含」——
     * 一个名叫 `email` 的字段不校验邮箱格式，才是真的隐式。
     *
     * ## 可覆盖
     *
     * 如果确实不需要校验（极少见），可以后续调 `->rules([])` 清空，
     * 或在 Form 层用 scope hook 拦截。但默认行为应该是安全的。
     */
    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);

        // 只在还没被显式设置过时追加（避免重复）
        if (! in_array('email', $this->rules, true)) {
            $this->rules[] = 'email';
        }
    }
}
