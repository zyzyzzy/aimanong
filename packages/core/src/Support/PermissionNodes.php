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
        $actions = ($node->meta['readonly'] ?? false) ? self::READONLY_ACTIONS : self::ACTIONS;

        /*
         * 自定义批量动作也是权限节点。
         *
         * 不做这一步的后果很具体：动作能调，但**没有任何权限节点管得住它** ——
         * 实测里一个 LLM 自建批量接口时就丢掉了权限校验，它自己在报告里
         * 标为安全缺口。框架既然收了这个动作，就得让它进权限体系。
         */
        foreach ($node->meta['customBatchActions'] ?? [] as $custom) {
            $name = is_array($custom) ? ($custom['name'] ?? null) : null;

            if (is_string($name) && $name !== '' && ! in_array($name, $actions, true)) {
                $actions[] = $name;
            }
        }

        return $actions;
    }

    /**
     * 动作的中文标签（含自定义动作）。
     *
     * 自定义动作的标签由声明方提供（`batchAction('ship', '标记为已发货')`）。
     */
    public static function labelFor(ResourceNode $node, string $action): string
    {
        foreach ($node->meta['customBatchActions'] ?? [] as $custom) {
            if (is_array($custom) && ($custom['name'] ?? null) === $action) {
                return (string) ($custom['label'] ?? $action);
            }
        }

        return self::label($action);
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }
}
