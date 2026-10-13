<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * 登录日志。
 *
 * 成功与失败**都要记**：只记成功等于放弃了「有人在爆破」这条线索。
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $username
 * @property string $status
 * @property string|null $reason
 * @property string|null $ip
 * @property string|null $user_agent
 */
class LoginLog extends Model
{
    protected $table = 'admin_login_logs';

    public const UPDATED_AT = null;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'username',
        'status',
        'reason',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * 状态词表 —— 列表徽章、筛选器、AI 自省共用一份。
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
        ];
    }
}
