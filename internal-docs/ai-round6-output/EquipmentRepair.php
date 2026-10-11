<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 设备报修单。
 *
 * photos / attachments 是 JSON 列，必须做 array cast，
 * 否则 API 返回字符串，前端多图/多文件组件会把它当成一个文件名。
 */
class EquipmentRepair extends Model
{
    protected $table = 'equipment_repairs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'repair_no',
        'equipment_name',
        'reporter',
        'description',
        'status',
        'urgency',
        'photos',
        'attachments',
        'reported_at',
        'handler',
        'handled_at',
        'remark',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'attachments' => 'array',
            'reported_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }
}
