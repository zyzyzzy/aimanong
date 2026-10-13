<?php

declare(strict_types=1);

namespace Aimanong\Exceptions;

/**
 * 可自愈异常基类。
 *
 * 所有面向 AI 的异常都必须提供三个字段：
 *   - didYouMean：最可能的正确写法
 *   - example：可直接复制的正确示例
 *   - hint / docs：补充说明
 *
 * 这样 AI 读到错误即可一次改对，无需反复试错。
 */
abstract class AiReadableException extends \RuntimeException
{
    /**
     * @return array<string, mixed>
     */
    abstract public function context(): array;

    /**
     * 结构化输出，供 JSON 响应与 MCP 工具使用。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => static::errorCode(),
            'message' => $this->getMessage(),
            ...$this->context(),
        ];
    }

    abstract public static function errorCode(): string;

    public function didYouMean(): ?string
    {
        $context = $this->context();

        $value = $context['did_you_mean'] ?? null;

        return is_string($value) ? $value : null;
    }
}
