<?php

declare(strict_types=1);

namespace Aimanong\Support;

use Aimanong\Schema\Ast\ResourceNode;

/**
 * 权限节点的动作定义 —— 两端共用。
 *
 * ## 为什么放在 core
 *
 * 「一个 Resource 有哪些动作」是**声明层的语义**，与框架无关。
 * 原先这套常量与中文标签写在 Laravel 的 `PermissionGate` 里，
 * ThinkPHP 侧要用只能自己抄一份 —— 而两份一旦不一致，
 * 「同一份声明两端行为相同」就是空话（在 `TreeBuilder` 上已经吃过一次）。
 */
final class PermissionNodes
{
    /** 可写 Resource 的动作全集 */
    public const ACTIONS = ['index', 'show', 'create', 'update', 'destroy', 'export'];

    /** 只读 Resource 的动作（没有 create/update/destroy） */
    public const READONLY_ACTIONS = ['index', 'show', 'export'];

    /** 动作 → 中文标签 */
    public const LABELS = [
        'index' => '查看列表',
        'show' => '查看详情',
        'create' => '新增',
        'update' => '编辑',
        'destroy' => '删除',
        'export' => '导出',
    ];

    /**
     * 这个 Resource 会生成哪些权限节点。
     *
     * 只读 Resource 不生成写权限 —— 否则权限列表里会出现
     * 「操作日志 · 新增」这种点了必然 403 的假权限，管理员会以为配错了。
     *
     * @return array<int, string>
     */
    public static function actionsFor(ResourceNode $node): array
    {
        return ($node->meta['readonly'] ?? false) ? self::READONLY_ACTIONS : self::ACTIONS;
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }
}
