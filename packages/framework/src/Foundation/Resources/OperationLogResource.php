<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Resources;

use Aimanong\Foundation\Audit\OperationLog;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Show\Show;

/**
 * 操作日志（内置 Resource）。
 *
 * ## 为什么 AI 需要它
 *
 * 这是 AI 排查「数据怎么变成这样了」的**唯一客观依据**：
 * 模型文件、代码、Schema 都只说明「应该怎样」，
 * 只有操作日志能回答「谁、在什么时候、把哪个字段改成了什么」。
 *
 * 因此 AI 自省接口（/__ai/capabilities.json）会明确告诉 AI：
 * 「数据对不上时，先查 admin-operation-logs」。
 *
 * 只读：审计数据允许被改就失去了意义。
 */
class OperationLogResource extends Resource
{
    public static function model(): string
    {
        return OperationLog::class;
    }

    public static function label(): string
    {
        return '操作日志';
    }

    public static function uri(): string
    {
        return 'admin-operation-logs';
    }

    public static function readonly(): bool
    {
        return true;
    }

    public static function menu(): array
    {
        // sort 95/96：排在「角色(90) / 权限(91) / 租户(92)」之后，
        // 日志属于「看了但不常改」的一类，放最后面。
        return ['group' => '系统', 'icon' => '📋', 'sort' => 95];
    }

    public static function grid(Grid $grid): void
    {
        $grid->perPage(20);
        $grid->export();

        $grid->column('id', 'ID')->sortable();
        $grid->column('created_at', '时间')->dateTime()->sortable();

        $grid->column('username', '账号')->searchable();

        // 动作存的是英文动词，列表要显示中文 —— 用 map() 而不是在库里存中文，
        // 这样以后改文案不需要刷数据。词表来自 Model（单一来源）。
        $grid->column('action', '动作')->map(OperationLog::actionOptions())->badge();

        $grid->column('resource_uri', '资源')->searchable();
        $grid->column('target_id', '记录 ID');
        $grid->column('method', '方法');
        $grid->column('status', '状态码')->dangerWhen('>=', 400)->sortable();
        $grid->column('ip', 'IP');
        $grid->column('duration_ms', '耗时(ms)')->sortable();
    }

    public static function show(Show $show): void
    {
        $show->fields([
            'id', 'created_at', 'username', 'method', 'path',
            'resource_uri', 'action', 'target_id', 'status',
            'ip', 'user_agent', 'payload', 'duration_ms',
        ]);
    }
}
