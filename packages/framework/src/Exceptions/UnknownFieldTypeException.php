<?php

declare(strict_types=1);

namespace Aimanong\Exceptions;

use Aimanong\Support\FieldType;

/**
 * 未知字段类型。
 *
 * AI 最容易犯的错就是类型名拼错，此异常必须给出：
 * 建议类型 + 全部可用类型 + 正确示例。
 */
class UnknownFieldTypeException extends AiReadableException
{
    protected ?string $suggestion = null;

    public static function make(string $given, string $class): static
    {
        $suggestion = FieldType::suggest($given);

        $message = $suggestion !== null
            ? "字段类型 '{$given}' 不存在，是否想用 '{$suggestion}'？"
            : "字段类型 '{$given}' 不存在。";

        $e = new static($message);
        $e->suggestion = $suggestion;

        return $e;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        /*
         * did_you_mean 必须在此显式输出。
         * 基类 didYouMean() 只读这个键 —— 曾漏掉它导致
         * llms.txt 承诺的字段实际返回 null，AI 拿不到建议。
         */
        return [
            'did_you_mean' => $this->suggestion,
            'hint' => '可用字段类型: '.implode(', ', FieldType::all()),
            'example' => '$form->text(\'name\')->label(\'名称\')->required();',
            'docs' => 'https://aimanong.com/llms/fields.txt',
        ];
    }

    public static function errorCode(): string
    {
        return 'UNKNOWN_FIELD_TYPE';
    }
}
