<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Resources;

use Aimanong\Foundation\Audit\LoginLog;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Show\Show;

/**
 * 登录日志（内置 Resource）。
 *
 * 成功与失败都记录：连续失败是「有人在爆破」的唯一线索。
 *
 * 只读：允许改登录日志等于允许抹掉痕迹。
 */
class LoginLogResource extends Resource
{
    public static function model(): string
    {
        return LoginLog::class;
    }

    public static function label(): string
    {
        return '登录日志';
    }

    public static function uri(): string
    {
        return 'admin-login-logs';
    }

    public static function readonly(): bool
    {
        return true;
    }

    public static function menu(): array
    {
        return ['group' => '系统', 'icon' => '🔐', 'sort' => 96];
    }

    public static function grid(Grid $grid): void
    {
        $grid->perPage(20);
        $grid->export();

        $grid->column('id', 'ID')->sortable();
        $grid->column('created_at', '时间')->dateTime()->sortable();
        $grid->column('username', '账号')->searchable();
        $grid->column('status', '结果')->badge()->map(LoginLog::statusOptions());
        $grid->column('reason', '原因');
        $grid->column('ip', 'IP')->searchable();
        $grid->column('user_agent', 'User-Agent');
    }

    public static function show(Show $show): void
    {
        $show->fields([
            'id', 'created_at', 'username', 'status', 'reason', 'ip', 'user_agent',
        ]);
    }
}
