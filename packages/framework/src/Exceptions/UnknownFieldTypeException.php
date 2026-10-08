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
    public static function make(string $given, string $class): static
    {
        $suggestion = FieldType::suggest($given);
        $available = implode(', ', FieldType::all());

        $message = $suggestion !== null
            ? "字段类型 '{$given}' 不存在，是否想用 '{$suggestion}'？"
            : "字段类型 '{$given}' 不存在。";

        return new static($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
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
