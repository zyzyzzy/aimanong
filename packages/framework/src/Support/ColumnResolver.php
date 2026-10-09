<?php

declare(strict_types=1);

namespace Aimanong\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

/**
 * 列名解析器 —— **查询列安全校验的唯一入口**。
 *
 * ## 为什么需要它
 *
 * 用户声明里的列名有四种可能：
 *   1. 本表真实列            → 直接可查
 *   2. 关联路径（a.b）       → 必须走 whereHas / 预加载，不能直接当列名
 *   3. 不存在的列            → 必须报错，不能静默
 *   4. 疑似 SQL 注入          → 必须拒绝
 *
 * 真实场景验证发现：把 2 当 1 用会 500，把 3 放过会**静默返回错误结果**，
 * 把 4 放过则是安全问题（虽然 Eloquent 会加引号兜底，但不能依赖）。
 *
 * ## 设计原则
 *
 * **编译期拒绝，运行期兼容。**
 * - 编译期：解析每个列名并校验，非法即抛异常（AI 立刻得到反馈）
 * - 运行期：已通过校验的列按类型分发（真实列 or 关联路径）
 */
class ColumnResolver
{
    /**
     * 已解析的缓存：模型类 → [列名 => 解析结果]。
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected static array $cache = [];

    /**
     * 解析单个列名。
     *
     * @param  string  $model  模型类名（本方法只做字符串解析，不使用模型）
     * @return array{type: string, column: string, relation: string|null, field: string|null}
     */
    public static function resolve(string $model, string $name): array
    {
        $isRelation = str_contains($name, '.');

        if ($isRelation) {
            [$relation, $field] = explode('.', $name, 2);

            return [
                'type' => 'relation',
                'column' => $name,
                'relation' => $relation,
                'field' => $field,
            ];
        }

        return [
            'type' => 'column',
            'column' => $name,
            'relation' => null,
            'field' => null,
        ];
    }

    /**
     * 校验列名是否可用于**查询**（搜索/排序/筛选）。
     *
     * 与"声明校验"不同：这里关心的是能否安全地进 SQL。
     *
     * @param  string  $model  模型类名（宽松接收，内部做 class_exists 判断）
     * @return array{ok: bool, reason?: string, type?: string, suggestion?: string|null}
     */
    public static function validate(string $model, string $name): array
    {
        // 1) SQL 注入模式：拒绝一切非标识符字符
        //    Eloquent 会加引号兜底，但不依赖它 —— 早点拦住更安全
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name)) {
            return [
                'ok' => false,
                'reason' => '包含非法字符（只允许字母、数字、下划线与点号）',
            ];
        }

        // 2) 关联路径：点号前必须是模型上真实存在的关联
        if (str_contains($name, '.')) {
            [$relation, $field] = explode('.', $name, 2);

            if (! class_exists($model)) {
                return ['ok' => false, 'reason' => "模型不存在: {$model}"];
            }

            if (! method_exists($model, $relation)) {
                return [
                    'ok' => false,
                    'reason' => "关联 {$relation} 在模型上不存在",
                ];
            }

            // 关联目标表的列也要校验（防止 a.ghost 这种）
            $target = self::relationTarget($model, $relation);
            $targetColumns = $target !== null ? self::realColumns($target) : [];

            // 目标表不可用时跳过（无数据库连接），不误报
            if ($targetColumns !== [] && ! in_array($field, $targetColumns, true)) {
                return [
                    'ok' => false,
                    'reason' => "关联 {$relation} 的模型上没有列 {$field}",
                    'suggestion' => self::suggest($field, $targetColumns),
                ];
            }

            return ['ok' => true, 'type' => 'relation'];
        }

        // 3) 本表真实列
        $real = self::realColumns($model);

        // 表不可用时（无数据库连接）跳过校验，不误报
        if ($real === []) {
            return ['ok' => true, 'type' => 'column'];
        }

        if (! in_array($name, $real, true)) {
            $suggestion = self::suggest($name, $real);

            return [
                'ok' => false,
                'reason' => "本表没有列 {$name}",
                'suggestion' => $suggestion,
            ];
        }

        return ['ok' => true, 'type' => 'column'];
    }

    /**
     * 批量校验，返回所有问题。
     *
     * @param  string  $model  模型类名
     * @param  array<int, string>  $names
     * @return array<int, array<string, mixed>>
     */
    public static function validateMany(string $model, array $names): array
    {
        $issues = [];

        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }

            $result = self::validate($model, $name);

            if (! $result['ok']) {
                $issues[] = [
                    'column' => $name,
                    'reason' => $result['reason'] ?? '未知原因',
                    'suggestion' => $result['suggestion'] ?? null,
                ];
            }
        }

        return $issues;
    }

    /**
     * 取模型的真实列。
     *
     * @param  string  $model  模型类名
     * @return array<int, string>
     */
    public static function realColumns(string $model): array
    {
        if (isset(self::$cache[$model]['__columns'])) {
            /** @var array<int, string> $cached */
            $cached = self::$cache[$model]['__columns'];

            return $cached;
        }

        $columns = [];

        try {
            if (class_exists($model)) {
                /** @var Model $instance */
                $instance = new $model;
                $table = $instance->getTable();

                if (Schema::hasTable($table)) {
                    $columns = Schema::getColumnListing($table);
                }
            }
        } catch (\Throwable) {
            // 无数据库连接等 —— 返回空，调用方据此跳过校验
        }

        self::$cache[$model]['__columns'] = $columns;

        return $columns;
    }

    /**
     * 取关联的目标模型类。
     *
     * @param  string  $model  模型类名
     * @return string|null 关联的目标模型类名
     */
    public static function relationTarget(string $model, string $relation): ?string
    {
        try {
            /** @var Model $instance */
            $instance = new $model;
            $result = $instance->{$relation}();

            if ($result instanceof Relation) {
                $related = $result->getRelated();

                return $related::class;
            }
        } catch (\Throwable) {
            // 关联定义有误等
        }

        return null;
    }

    /**
     * 为拼错的列名给建议（编辑距离）。
     *
     * @param  array<int, string>  $available
     */
    public static function suggest(string $name, array $available): ?string
    {
        $best = null;
        $shortest = -1;

        foreach ($available as $real) {
            $lev = levenshtein(strtolower($name), strtolower($real));

            if ($lev < $shortest || $shortest < 0) {
                $best = $real;
                $shortest = $lev;
            }
        }

        return ($shortest >= 0 && $shortest <= 3) ? $best : null;
    }

    /**
     * 清除缓存（测试用）。
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
