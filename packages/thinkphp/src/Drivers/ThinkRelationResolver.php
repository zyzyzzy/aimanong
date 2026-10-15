<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Drivers;

use Aimanong\Contracts\RelationResolver;
use think\facade\Db;
use think\Model;
use think\model\Relation;
use think\model\relation\BelongsTo;

/**
 * ThinkORM 版外键解析 —— 与 Laravel 侧同一套语义。
 *
 * 候选、目标表名、主键全部从模型上的 `belongsTo` 关联里读出来，
 * 声明方只写 `$form->belongsTo('author_id', '作者')`。
 */
final class ThinkRelationResolver implements RelationResolver
{
    /**
     * 自动挑显示列的候选顺序（与 Laravel 侧**逐字相同**）。
     *
     * 两端各写一份的话迟早漂移 —— 而漂移的表现是「同一个声明，
     * 换个框架后下拉里显示的东西变了」，极难发现。
     *
     * @var array<int, string>
     */
    private const LABEL_CANDIDATES = ['name', 'title', 'label', 'username', 'nickname', 'code', 'no', 'slug'];

    public function resolve(string $model, string $relation, ?string $labelColumn = null): ?array
    {
        if (! class_exists($model)) {
            return null;
        }

        try {
            $instance = new $model;
        } catch (\Throwable) {
            return null;
        }

        if (! method_exists($instance, $relation)) {
            return null;
        }

        try {
            $related = $instance->{$relation}();
        } catch (\Throwable) {
            // 关联里引用了不存在的类 / 表还没建 —— 都是正常中间态
            return null;
        }

        if (! $related instanceof Relation) {
            return null;
        }

        // 只处理 belongsTo（hasMany/many-to-many 做下拉没有意义）
        if (! $related instanceof BelongsTo) {
            return null;
        }

        try {
            $target = $related->getModel();
        } catch (\Throwable) {
            return null;
        }

        /*
         * `Relation::getModel()` 的声明类型是 `Modelable`（接口），
         * 而 getPk()/field() 在**具体模型**上。收窄成 think\Model 再用。
         */
        if (! $target instanceof Model) {
            return null;
        }

        $table = $target->getTable();
        $pk = $target->getPk();
        $key = is_string($pk) && $pk !== '' ? $pk : 'id';

        $label = $labelColumn ?? $this->guessLabel($table);

        if ($label === null) {
            return null;
        }

        try {
            $rows = $target->field([$key, $label])->select()->toArray();
        } catch (\Throwable) {
            return null;
        }

        $options = [];

        foreach ($rows as $row) {
            $options[] = [
                'value' => $row[$key] ?? null,
                'label' => (string) ($row[$label] ?? ''),
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
            // ⚠️ 不能 new Query —— ThinkORM 的 BaseQuery 构造函数要一个连接实例。
            //    用 Db 门面（与 ThinkIntrospector 同一写法）。
            $columns = array_keys(Db::table($table)->getFields());
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
