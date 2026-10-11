<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * 操作日志。
 *
 * 只读模型：框架不提供写接口，只能由 {@see AuditRecorder} 写入。
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $username
 * @property string $method
 * @property string $path
 * @property string|null $resource_uri
 * @property string|null $action
 * @property string|null $target_id
 * @property int $status
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $payload
 * @property int|null $duration_ms
 */
class OperationLog extends Model
{
    protected $table = 'admin_operation_logs';

    /**
     * 审计日志不允许被修改。
     *
     * 表格没有 updated_at —— 只写一次，永不更新。
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'username',
        'method',
        'path',
        'resource_uri',
        'action',
        'target_id',
        'status',
        'ip',
        'user_agent',
        'payload',
        'duration_ms',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * 动作词表。
     *
     * **单一来源**：列表页的徽章、show 页面、AI 自省读的都是这一份。
     * 之前把映射同时写在 Model 和 Resource 里 ——
     * 加一个动作要改两处，必漂移。
     *
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        return [
            'store' => '新增',
            'update' => '修改',
            'destroy' => '删除',
            'export' => '导出',
            'move' => '移动',
            'index' => '查看列表',
            'show' => '查看详情',
            'login' => '登录',
            'logout' => '退出登录',
        ];
    }

    /**
     * 人类可读的操作标签。
     */
    public function actionLabel(): string
    {
        $key = $this->action ?? '';

        return self::actionOptions()[$key] ?? ($key === '' ? '—' : $key);
    }
}
