<?php

declare(strict_types=1);

namespace Aimanong\Support;

/**
 * 字段类型注册表。
 *
 * AI-First 设计：所有可用字段类型必须在此登记，
 * 使 /__ai/capabilities.json 能完整枚举 —— AI 不需要猜。
 */
class FieldType
{
    /**
     * 类型定义表：类型名 → JSON Schema 类型 + 额外属性。
     *
     * @var array<string, array{json: string, php: string}>
     */
    protected const TYPES = [
        'text' => ['json' => 'string', 'php' => 'string'],
        'textarea' => ['json' => 'string', 'php' => 'string'],
        'number' => ['json' => 'integer', 'php' => 'int'],
        'decimal' => ['json' => 'number', 'php' => 'float'],
        'select' => ['json' => 'string', 'php' => 'string'],
        'switch' => ['json' => 'boolean', 'php' => 'bool'],
        'date' => ['json' => 'string', 'php' => 'string'],
        'datetime' => ['json' => 'string', 'php' => 'string'],
        'email' => ['json' => 'string', 'php' => 'string'],
        'url' => ['json' => 'string', 'php' => 'string'],
        'hidden' => ['json' => 'string', 'php' => 'string'],
        'display' => ['json' => 'string', 'php' => 'mixed'],
    ];

    /**
     * 全部可用类型名。
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * 映射到 JSON Schema 类型。
     */
    public static function toJsonType(string $type): string
    {
        return self::TYPES[$type]['json'] ?? 'string';
    }

    /**
     * 映射到 PHP 类型（用于 TS 类型生成）。
     */
    public static function toPhpType(string $type): string
    {
        return self::TYPES[$type]['php'] ?? 'string';
    }

    /**
     * 模糊匹配：AI 打错字时给出建议。
     *
     * 这是"可自愈错误"的基础设施。
     */
    public static function suggest(string $input): ?string
    {
        if (self::exists($input)) {
            return $input;
        }

        $best = null;
        $shortest = -1;

        foreach (self::all() as $type) {
            $lev = levenshtein($input, $type);

            // 距离小于 3 才认为是拼写错误
            if ($lev <= 2 && ($lev < $shortest || $shortest < 0)) {
                $best = $type;
                $shortest = $lev;
            }
        }

        return $best;
    }
}
