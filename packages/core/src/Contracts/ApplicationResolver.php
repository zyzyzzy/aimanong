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
 *
 * ## 🔴 驱动实现必须遵守的三条硬规则
 *
 * 这三条都是**真实事故**换来的 —— 每条都曾经「不报错但行为错」，
 * 而且**两个驱动各犯了一遍**（同一套语义重写时，第一版的隐含假设会暴露）。
 *
 * ### 1. HTTP 下 `current()` 必须由**请求路径**推断
 *
 * 不能只读「某个地方 switch 过」的残留状态。
 * 路径推断的权威在驱动自己的上下文类里（Laravel 是 `ApplicationContext`）。
 *
 * 反例：曾经 `current()` 只读进程状态，而**没有任何一处在请求期写它**，
 * 于是恒为默认应用 → 非默认后台拿默认应用的权限节点判权限 → **全拒**。
 * 而默认后台恰好正确，所以只有别的后台坏，且不报错。
 *
 * ### 2. 无请求上下文（CLI / 队列）不得读**进程级**状态
 *
 * 要回退到「配置里的第一个应用」。
 *
 * 反例：路由注册时逐应用 switch 后没恢复 → CLI 的 `current` 停在
 * 最后一个应用；Laravel 的 `Context` 是进程级单例，跨命令不清零。
 *
 * ### 3. 🔴 **归属校验必须排在权限校验之前，且返回 404**
 *
 * 不在当前应用的 Resource，要**看起来就像不存在**。
 *
 * 若先做权限校验，会返回 403 —— 那等于告诉对方
 * 「这个资源存在，只是你没权限」，**把后台结构泄露出去**。
 *
 * ⚠️ 这条最容易被写错：直觉是「先鉴权再取资源」，
 * 而正确顺序是「先确认资源属于本后台（否则 404），再鉴权」。
 * **两个驱动都写错过一次**（TP 的断言先抓到，回头发现 Laravel 同样中招）。
 *
 * 落地位置：拦截**所有**入口（Laravel `ResourceController::authorize_()`
 * 的**第一行**、ThinkPHP `ResourceController::deny()` 的**第一行**），
 * 而不是 `resolve()` 里 —— `resolve()` 在这些方法之后才被调用，轮不到。
 */
interface ApplicationResolver
{
    /**
     * 占位符：属于「默认（第一个）应用」。
     *
     * ## 为什么需要它
     *
     * 框架内置的系统管理类 Resource（管理员账号 / 角色 / 日志 / 字典）
     * 应当只出现在**主后台**里。但「主后台叫什么名字」是用户定的 ——
     * 写死 `'admin'` 的话，把应用命名为 `sys` / `merchant` 的用户
     * 会发现这些页面**在任何后台都看不见**（静默消失，没有任何报错）。
     *
     * 所以声明时用这个占位符，由 `Registry::belongsTo()` 在运行时
     * 解析成 `names()` 里的**第一个**应用。
     *
     * ```php
     * public static function applications(): array
     * {
     *     return [ApplicationResolver::PRIMARY];   // 只属于主后台
     * }
     * ```
     */
    public const PRIMARY = '@primary';

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
