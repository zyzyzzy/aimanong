<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Dict;

use Illuminate\Database\Eloquent\Model;

/**
 * 字典分类。
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_locked
 * @property int $sort
 */
class DictType extends Model
{
    protected $table = 'admin_dict_types';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_locked',
        'sort',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'sort' => 'integer',
    ];

    protected static function booted(): void
    {
        // 字典变了，缓存必须立刻失效，否则后台改了值页面还是旧的
        static::saved(fn (): bool => Dictionary::flush());
        static::deleted(fn (): bool => Dictionary::flush());
    }
}
