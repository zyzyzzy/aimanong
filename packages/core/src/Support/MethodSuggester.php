<?php

declare(strict_types=1);

namespace Aimanong\Support;

/**
 * 「方法不存在」时的可自愈报错 —— 生成逻辑。
 *
 * ## 为什么需要它
 *
 * PHP 原生报错只有一句：
 *
 *     Call to undefined method Aimanong\Form\Fields\Select::showWhen()
 *
 * 而这个类里**明明有** `visibleWhen()`。差一个词，AI 却无从知道该叫什么 ——
 * 实测中一个 LLM 为此反射了 142 个框架类，才确认「不是文档没写，是真的没有」。
 *
 * 加上提示之后：
 *
 *     不存在方法 showWhen()。
 *     你是不是想调用: visibleWhen()
 *     可用方法: default, hidden, label, options, required, visibleWhen, ...
 *
 * 这是「错误可自愈」这条原则最便宜的一次落地：不需要模型更强，
 * 只需要把**框架已经知道的信息**说出来。
 *
 * 逻辑放在普通类而不是 trait 里：trait 的静态方法不能被直接调用
 * （PHP 8.1 起会报 deprecation），而这里既需要被 `__call` 用，
 * 也需要被测试与其他错误分支单独调用。
 */
final class MethodSuggester
{
    /**
     * @param  array<int, string>  $extra  类方法表之外、但也算「可用名称」的清单
     *                                     （如 Form 的字段类型不在方法表里）
     * @param  string  $extraLabel  $extra 的展示标签
     */
    public static function message(
        string $class,
        string $method,
        array $extra = [],
        string $extraLabel = '其他可用名称'
    ): string {
        $available = array_values(array_filter(
            get_class_methods($class),
            static fn (string $m): bool => ! str_starts_with($m, '__')
        ));

        sort($available);

        $candidates = array_merge($available, $extra);
        $suggestions = self::closestNames($method, $candidates);

        $message = "{$class} 上不存在方法 {$method}()。";

        if ($suggestions !== []) {
            $message .= "\n你是不是想调用: ".implode(' / ', array_map(
                static fn (string $s): string => $s.'()',
                $suggestions
            ));
        }

        if ($available !== []) {
            $message .= "\n可用方法: ".implode(', ', $available);
        }

        if ($extra !== []) {
            $message .= "\n{$extraLabel}: ".implode(', ', $extra);
        }

        return $message;
    }

    /**
     * 按编辑距离挑最近的几个名字。
     *
     * 阈值随名字长度放宽：短名字（`map`）只容忍 1 个字符差，
     * 长名字（`visibleWhen`）可以差 3 个 —— 否则拼错一半也会被「建议」一堆无关的。
     *
     * @param  array<int, string>  $haystack
     * @return array<int, string>
     */
    public static function closestNames(string $needle, array $haystack, int $max = 3): array
    {
        $needle = strtolower($needle);
        $needleLen = strlen($needle);
        $limit = max(1, (int) floor($needleLen / 3));

        $scored = [];

        foreach (array_unique($haystack) as $name) {
            $name = (string) $name;

            // 比较用小写，但**回显要保留原大小写** —— 否则建议里出现
            // `visiblewhen()` 这种真实代码里不存在的写法，反而误导
            $candidate = strtolower($name);

            $distance = levenshtein($needle, $candidate);

            /*
             * 两条判据，取更近的那个当分数。
             *
             * 只靠编辑距离会漏掉**语义近似但拼写差很远**的错名 ——
             * 实测最常见的那次就是：`showWhen()`（想表达「满足条件才显示」）
             * 而真名是 `visibleWhen()`。两者编辑距离 6，任何合理阈值都够不着，
             * 字符串相似度也只有 0.53 —— 它压根不是拼写错误，是用词不同。
             *
             * 但它们的**公共后缀是 `when`**。所以补一条后缀判据：
             * 含义相近的方法名，往往共享同一个功能词尾（when / able / by / id …）。
             */
            $suffix = self::commonSuffixLength($needle, $candidate);
            $suffixScore = $suffix >= 4 ? (10 - min(9, $suffix)) : PHP_INT_MAX;

            $score = min($distance <= $limit ? $distance : PHP_INT_MAX, $suffixScore);

            if ($score !== PHP_INT_MAX) {
                $scored[$name] = $score;
            }
        }

        asort($scored);

        return array_slice(array_keys($scored), 0, $max);
    }

    /** 两个字符串从末尾起相同的字符数 */
    private static function commonSuffixLength(string $a, string $b): int
    {
        $n = min(strlen($a), strlen($b));

        for ($i = 1; $i <= $n; $i++) {
            if ($a[strlen($a) - $i] !== $b[strlen($b) - $i]) {
                return $i - 1;
            }
        }

        return $n;
    }
}
