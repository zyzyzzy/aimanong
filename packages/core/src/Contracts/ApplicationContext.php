<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 当前请求所属后台的上下文。
 *
 * ## 为什么需要这个契约
 *
 * 多应用下「我在哪个后台」是**每个请求都不同**的信息，
 * 而 `config('aimanong.applications')` 是全局的 —— 直接读配置会被污染。
 * 所以框架规定：**要用前缀/guard 就走 `Aimanong::context()`**。
 * （`ApplicationContextTest` 有一条静态扫描断言盯这件事。）
 *
 * 在此之前 `Aimanong::context()` 的返回类型是 `object`，
 * 于是 core 里的消费者（如 `Extend\Extension::routes()`）
 * 只好用文档注释把变量标注成 `\Aimanong\Application\ApplicationContext`
 * —— 而那个类是 **Laravel 驱动**的，core 不该认识它。
 *
 * ⚠️ 写这段注释时踩过一次：在文档块里写嵌套的「星号 + 斜杠」
 * 会把外层注释**提前闭合**，后面的文字变成代码 → 解析错误。
 * 与 blade 里「注释不保护指令」是同一类坑。
 *
 * 这个契约只放**core 真正需要的**那一个方法；驱动可以有自己的
 * 更宽的类（Laravel 的 `ApplicationContext` 还有 `guard()` /
 * `current()` 等），实现本接口即可。
 */
interface ApplicationContext
{
    /**
     * 当前后台的路由前缀，如 `/admin`。
     */
    public function prefix(): string;
}
