<?php

declare(strict_types=1);

namespace Aimanong\Support;

/**
 * 枚举 case → 显示标签。
 *
 * ## 为什么要抽出来
 *
 * 「枚举的 label() 从来没被调用过」这个 bug 出现过**两次**：
 *
 *   ① 列表列 `->using(Enum::class)`：只往 schema 写 `enum: 类名`，
 *      前端解析不了 PHP 类名 → 列表照旧显示 `active`/`disabled`
 *   ② 表单下拉 `->options(Enum::cases())`：写死 `label => $case->value`
 *      → 下拉里显示英文原值
 *
 * 两次都是「声明合法、`ai:verify` 通过、页面就是不对」。
 *
 * 修的时候**只在一处定义**取标签的规则 —— 否则两边会各自漂移，
 * 下次再修一个漏一个。
 */
final class EnumLabel
{
    /**
     * 依次尝试，都取不到就退回 case 名（`Active` 也比 `active` 可读）。
     */
    public static function of(\UnitEnum $case): string
    {
        foreach (['label', 'getLabel'] as $method) {
            if (method_exists($case, $method)) {
                $value = $case->{$method}();

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $case->name;
    }

    /**
     * 枚举的值（backed 用 value，pure 用 name）。
     */
    public static function value(\UnitEnum $case): string|int
    {
        return $case instanceof \BackedEnum ? $case->value : $case->name;
    }

    /**
     * 整个枚举 → `[值 => 标签]` 映射。
     *
     * @param  class-string<\UnitEnum>  $enum
     * @return array<string|int, string>
     */
    public static function map(string $enum): array
    {
        $map = [];

        foreach ($enum::cases() as $case) {
            $map[self::value($case)] = self::of($case);
        }

        return $map;
    }
}
