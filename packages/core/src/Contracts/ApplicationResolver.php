<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 多应用（多后台）解析器契约。
 *
 * ## 什么是「应用」
 *
 * 一个项目里同时跑多个互相隔离的后台：
 *
 *   /admin    总管理后台    运营用，看全部
 *   /user     普通用户后台  用户用，只看自己的
 *   /agent    代理商后台    代理商用，看名下客户
 *
 * 每个应用有独立的：路由前缀、登录守卫、用户模型、菜单、权限。
 *
 * ## 为什么要有这个契约
 *
 * 「当前是哪个后台」这件事的**判定逻辑**是框架无关的（看请求路径），
 * 但「怎么拿到请求路径」各框架不同（Laravel 用 `Request::path()`，
 * ThinkPHP 用 `Request::pathinfo()`）。
 *
 * 所以这里只定义**能力与语义**，由驱动包实现取路径那一层。
 * **不要**把应用解析直接写在某个驱动包里 —— 否则另一个驱动要重写一遍，
 * 这正是前面跨框架共出现过 14 处语义差异的根源。
 *
 * ## 单应用（默认）时的行为
 *
 * 未注册解析器、或 `enabled()` 返回 false 时，
 * `current()` 应返回一个稳定的默认名（如 `default`）。
 * 这样所有按应用过滤的代码**无需分叉**：只有一个应用时，
 * 任何 Resource 都属于它。
 */
interface ApplicationResolver
{
    /**
     * 当前应用名。
     *
     * 无请求上下文（CLI）时返回默认应用名，不得返回空字符串或 null。
     */
    public function current(): string;

    /**
     * 是否启用了多应用。
     *
     * false 时表示单后台模式，`current()` 返回默认名。
     */
    public function enabled(): bool;

    /**
     * 所有已注册的应用名。
     *
     * @return array<int, string>
     */
    public function names(): array;

    /**
     * 某应用的路由前缀（不含首尾斜杠）。
     */
    public function prefix(string $name): string;

    /**
     * 某应用的登录守卫名。
     */
    public function guard(string $name): string;

    /**
     * 某应用的用户模型类名。
     *
     * @return class-string
     */
    public function model(string $name): string;

    /**
     * 某应用的显示标题。
     */
    public function title(string $name): string;
}
