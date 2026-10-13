<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Dict;

use Illuminate\Database\Eloquent\Model;

/**
 * 字典条目。
 *
 * @property int $id
 * @property string $type_code
 * @property string $value
 * @property string $label
 * @property int $sort
 * @property bool $enabled
 * @property string|null $color
 * @property array<string, mixed>|null $extra
 */
class DictItem extends Model
{
    protected $table = 'admin_dict_items';

    protected $fillable = [
        'type_code',
        'value',
        'label',
        'sort',
        'enabled',
        'color',
        'extra',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'sort' => 'integer',
        'extra' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(fn (): bool => Dictionary::flush());
        static::deleted(fn (): bool => Dictionary::flush());
    }

    /**
     * 徽章语义色词表。
     *
     * 与 design-system 的 am-badge--* 一一对应；
     * 写错颜色名不会报错，只会退化成灰徽章（实测踩过），
     * 所以这里用白名单把它锁住。
     *
     * @return array<string, string>
     */
    public static function colorOptions(): array
    {
        return [
            'success' => '绿（成功）',
            'danger' => '红（危险）',
            'warning' => '橙（警告）',
            'info' => '蓝（信息）',
            'muted' => '灰（中性）',
        ];
    }
}
