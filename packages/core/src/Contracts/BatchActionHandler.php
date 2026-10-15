<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 自定义批量动作的处理器。
 *
 * ## 为什么是「引用一个类」而不是「传一个闭包」
 *
 * ADR-003 定的是：**声明层只放数据，不放行为**。理由是声明要被
 * 序列化、被自省、被 AI 阅读、被跨框架执行 —— 闭包这四件都做不到。
 *
 * 但「声明层不收闭包」不等于「不能有自定义批量动作」——
 * 中间那条路是**引用**：声明里放类名（数据），框架按名实例化并调用。
 * 这样既守住了 ADR-003，又让动作可声明。
 *
 * ## 实现方拿到什么
 *
 * 只有模型类名与被选中的 id。**不传请求对象、不传 Resource** ——
 * 处理器是应用代码，框架不该把内部结构泄进去，否则框架一改它就碎。
 * 需要请求上下文时自己从容器取。
 *
 * ## 权限
 *
 * 框架会先校验 `{uri}.{动作名}` 权限节点（与内置动作同一套机制），
 * 通过后才调用 `handle()`。所以处理器里**不需要**再判一次权限。
 */
interface BatchActionHandler
{
    /**
     * 执行批量动作。
     *
     * @param  string  $model  当前 Resource 的模型类名
     * @param  array<int, mixed>  $ids  被选中的主键
     *
     * @return int  受影响的行数（回给前端做提示）
     */
    public function handle(string $model, array $ids): int;
}
