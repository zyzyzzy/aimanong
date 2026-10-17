<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Contracts\Resource as ResourceContract;
use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Layout\Layout;
use Aimanong\Show\Show;
use Aimanong\Tree\Tree;

/**
 * Resource 基类。
 *
 * 用户继承此类，实现 grid() / form() / show() 三个静态方法，
 * 框架自动产出：REST API、前端页面、JSON Schema、
 * TypeScript 类型、OpenAPI 文档、AI 提示词。
 *
 * Aimanong 约定：三个方法都是静态的、签名唯一。
 */
abstract class Resource implements ResourceContract
{
    /**
     * 菜单声明（**可选**）。子类可覆盖。
     *
     * 不覆盖时，菜单默认用 label 作为名称、不分组、sort=100。
     *
     * 可声明的键：
     *   - group   分组名（如「内容管理」），不填则不分组
     *   - icon    图标（emoji 或字符），可留空
     *   - sort    排序（小的在前），默认 100
     *   - label   菜单显示名，默认用 label()
     *   - visible 是否显示，默认 true（false 则隐藏，适合后台工具页）
     *
     * ```php
     * public static function menu(): array
     * {
     *     return ['group' => '内容管理', 'icon' => '📄', 'sort' => 10];
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    public static function menu(): array
    {
        return [];
    }

    /**
     * 本 Resource 属于哪些后台（应用）。
     *
     * ```php
     * public static function applications(): array
     * {
     *     return ['admin', 'agent'];   // 总后台与代理商后台都能看到
     * }
     * ```
     *
     * **返回空数组 = 属于所有后台**（默认）。
     * 这样单应用项目零改动，多应用项目只需给「不该出现在某后台」的
     * Resource 显式声明归属。
     *
     * 为什么放在 Resource 里而不是注册时指定：
     * 声明要**自包含** —— AI 读一个 Resource 就知道它属于哪些后台，
     * 不用去 ServiceProvider 里翻。
     *
     * @return array<int, string>
     */
    public static function applications(): array
    {
        return [];
    }

    /**
     * 列表页定义。子类覆盖。
     */
    public static function grid(Grid $grid): void
    {
        // 默认不定义列，子类覆盖
    }

    /**
     * 表单页定义。子类覆盖。
     */
    public static function form(Form $form): void
    {
        // 默认不定义字段，子类覆盖
    }

    /**
     * 详情页定义。子类覆盖。
     */
    public static function show(Show $show): void
    {
        // 默认不定义字段，子类覆盖
    }

    /**
     * 详情页**结构**定义（分组 / 标签页）。子类覆盖。
     *
     * ```php
     * public static function layout(Layout $layout): void
     * {
     *     $layout->tabs([
     *         '基本' => ['name', 'level', 'industry'],
     *         '联系' => ['phone', 'website', 'address'],
     *     ]);
     * }
     * ```
     *
     * ⚠️ 与 `show()` 的分工：`show()` 说**有哪些字段**，
     * `layout()` 说**怎么摆**。只在 `show()` 里声明、没进任何分组的字段
     * 会被**追加到最后一组**（不会静默消失）；引用了不存在的字段则
     * **编译期报错**。
     *
     * 不声明时详情页按平铺渲染 —— 与加这个能力之前完全一致。
     */
    public static function layout(Layout $layout): void
    {
        // 默认不分组，子类覆盖
    }

    /**
     * 树形结构定义。子类覆盖以启用树形页面。
     *
     * 用法：$tree->parentColumn('parent_id')->titleColumn('name');
     */
    public static function tree(Tree $tree): void
    {
        // 默认不启用树形，子类覆盖
    }

    /**
     * 是否只读（**可选**）。子类可覆盖。
     *
     * 只读 Resource 只提供「列表 + 详情 + 导出」：
     *   - 前端不显示「新增 / 编辑 / 删除」按钮
     *   - 后端 store()/update()/destroy() 直接 403
     *   - 权限节点**只生成** index/show/export
     *
     * 典型用途：审计日志、监控、报表快照 ——
     * 这类数据只应该由系统写入；人在后台能随手改，审计就失去意义。
     *
     * ```php
     * public static function readonly(): bool { return true; }
     * ```
     */
    /**
     * 多对多关联写入前的**授权钩子**。
     *
     * ## 为什么需要它
     *
     * 多对多字段（如管理员的「角色」）走 `sync($ids)` 直接落库。
     * 框架原先**不做任何校验** —— 于是：
     *
     *     一个只有 `admin-users.update` 权限的账号，
     *     把 roles 提交成 [超级管理员的角色 id]，
     *     就给自己加上了 super_admin。
     *
     * 实测（2026-10-16）：改前处处 403，改后处处 200 —— 完全提权。
     *
     * 权限系统管的是「你能不能进这个接口」，
     * 管不了「你提交的**这条关联数据**合不合法」—— 后者是业务语义，
     * 只有声明方知道。所以这里开一个默认放行的钩子给声明方覆盖。
     *
     * ## 用法
     *
     *     public static function authorizeRelation(string $relation, array $ids): void
     *     {
     *         if ($relation === 'roles' && ! currentUser()->isSuper()) {
     *             throw new \RuntimeException('不能授予超级管理员角色');
     *         }
     *     }
     *
     * 抛出的异常由控制器统一转成 403。
     *
     * @param  string  $relation  关联方法名（如 roles）
     * @param  array<int, mixed>  $ids  本次要同步过去的 id
     *
     * @throws \Throwable 声明方拒绝时抛出，控制器转成 403
     */
    public static function authorizeRelation(string $relation, array $ids): void
    {
        // 默认放行 —— 只有声明方知道自己的关联该受什么约束
    }

    public static function readonly(): bool
    {
        return false;
    }

    /**
     * 资源 URI，默认按类名推导（UserResource → users）。
     */
    public static function uri(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();
        $name = str_ends_with($short, 'Resource')
            ? substr($short, 0, -strlen('Resource'))
            : $short;

        return strtolower($name).'s';
    }

    /**
     * 人类可读名称，默认用 URI。
     */
    public static function label(): string
    {
        return static::uri();
    }

    /**
     * 绑定模型，子类必须覆盖。
     */
    public static function model(): string
    {
        throw new \RuntimeException(
            'Resource ['.static::class.'] 必须实现 model() 方法，返回 Eloquent 模型类名。'
        );
    }
}
