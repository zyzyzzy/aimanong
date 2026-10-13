<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

use Aimanong\Registry;
use Aimanong\Support\Asset;

/**
 * 框架运行时接缝 —— `Aimanong\Aimanong` 这个公开入口所依赖的一切。
 *
 * ## 为什么需要它
 *
 * `Aimanong\Aimanong` 是框架的**公开入口**：用户代码写
 * `Aimanong::registry()->register(...)`（文档也这么教），
 * 框架自己的 10 个 Blade 模板写 `Aimanong::url()/brand()/asset()/version()`。
 * 所以这个类名**必须在两个框架下都存在** —— 它只能住在 core。
 *
 * 但它内部原先直接调 `app('auth')`、`config(...)`、`app(Registry::class)`
 * 等 Laravel 专属设施。把这个契约抽出来之后：
 *
 *   core    → `Aimanong`（入口，只依赖本契约）
 *   laravel → `LaravelRuntime implements FrameworkRuntime`
 *   thinkphp→ `ThinkRuntime   implements FrameworkRuntime`
 *
 * ## 为什么 `guard/context/application/extensions` 返回 `object`
 *
 * 它们的真实类型（`AdminGuard` / `ApplicationContext` / `ApplicationManager` /
 * `ExtensionManager`）都住在驱动包里 —— core 若写死这些类名，就又绑回
 * Laravel 了。
 *
 * 这个代价是**刻意接受的**：这 4 个方法在框架自身只有 13 处调用，
 * 调用点用 `@var` 局部变量恢复类型即可；而用户日常用的是
 * `registry()/url()/brand()/version()/user()`（85 处），全部有精确类型。
 *
 * 取舍理由：把 4 个低频访问器的静态类型，换成「公开入口不分裂」。
 * 入口分裂成两个类才是真问题 —— 那是两份声明，必然漂移。
 */
interface FrameworkRuntime
{
    // ── core 能精确命名返回类型的部分 ──────────────────

    /**
     * 后台 URL 前缀。多应用下会随当前应用变化。
     */
    public function urlPrefix(): string;

    /**
     * 读配置（品牌名等）。
     */
    public function config(string $key, mixed $default = null): mixed;

    /**
     * Resource 注册表。
     */
    public function registry(): Registry;

    /**
     * 前端静态资源。
     */
    public function asset(): Asset;

    /**
     * 当前登录用户；未登录返回 null。
     *
     * 返回 `object` 而不是某个具体模型：core 不认识 `Administrator`。
     */
    public function user(): ?object;

    // ── 各框架自己的对象（core 无法命名其类型）──────────

    /**
     * 当前后台的认证 guard。
     */
    public function guard(): object;

    /**
     * 多应用上下文。
     */
    public function context(): object;

    /**
     * 多应用管理器。
     */
    public function application(): object;

    /**
     * 扩展管理器。
     */
    public function extensions(): object;
}
