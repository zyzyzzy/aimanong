<?php

declare(strict_types=1);

namespace Aimanong\Drivers\Eloquent;

use Aimanong\Contracts\RelationResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Eloquent 版外键解析。
 *
 * 从模型上的 `belongsTo` 关联里读出「目标模型 / 表 / 主键」，再查一次候选。
 * 这些信息**框架本来就拿得到** —— 此前却要每个声明方自己写：
 *
 *     ->options(Author::pluck('name','id')->all())
 *     ->rules('exists:authors,id')
 *
 * 而这两个都是最容易写错、错了又不报错的地方：实测里 `categories`
 * 被写成 `categorys`（模型里的 `$table` 本身也是错的），一路错到运行时才炸。
 */
final class EloquentRelationResolver implements RelationResolver
{
    /**
     * 自动挑显示列时的候选顺序。
     *
     * 按「人类一眼能认出这条记录」的概率排 —— 业务编号（code/no）比自增 id
     * 有用，但不如名字直观。
     *
     * @var array<int, string>
     */
    private const LABEL_CANDIDATES = ['name', 'title', 'label', 'username', 'nickname', 'code', 'no', 'slug'];

    public function resolve(string $model, string $relation, ?string $labelColumn = null): ?array
    {
        if (! class_exists($model)) {
            return null;
        }

        $instance = new $model;

        if (! $instance instanceof Model || ! method_exists($instance, $relation)) {
            return null;
        }

        try {
            $related = $instance->{$relation}();
        } catch (\Throwable) {
            // 关联里引用了不存在的类 / 表还没迁 —— 都是正常中间态
            return null;
        }

        if (! $related instanceof BelongsTo) {
            return null;
        }

        $target = $related->getRelated();
        $table = $target->getTable();
        $key = $target->getKeyName();

        $label = $labelColumn ?? $this->guessLabel($table);

        if ($label === null) {
            return null;
        }

        try {
            $rows = $target->newQuery()->get([$key, $label]);
        } catch (\Throwable) {
            return null;
        }

        $options = [];

        foreach ($rows as $row) {
            $options[] = [
                'value' => $row->getAttribute($key),
                'label' => (string) $row->getAttribute($label),
            ];
        }

        return [
            'options' => $options,
            'exists' => "exists:{$table},{$key}",
            'labelColumn' => $label,
        ];
    }

    private function guessLabel(string $table): ?string
    {
        try {
            $columns = Schema::getColumnListing($table);
        } catch (\Throwable) {
            return null;
        }

        foreach (self::LABEL_CANDIDATES as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
