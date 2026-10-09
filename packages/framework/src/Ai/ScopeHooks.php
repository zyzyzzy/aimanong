<?php

declare(strict_types=1);

namespace Aimanong\Ai;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 数据作用域钩子 —— **框架提供的官方拦截点**。
 *
 * ## 为什么需要它
 *
 * 多租户场景验证发现：一个 Resource 注册后默认**全表裸奔**，
 * 框架不提供任何「在这里收窄查询」的官方位置。
 * 用户只能自己在模型层写全局作用域，导致：
 *
 *   1. 没有标准做法，每人各写一套
 *   2. 框架无法感知，也就无法验证（校验器对安全需求完全无感）
 *   3. 漏写了框架不会提醒
 *
 * ## 设计
 *
 * 本类**不实现**多租户（那属于业务层），
 * 而是提供**注册点 + 可自省的元信息**：
 *
 *   1. 用户注册自己的作用域回调
 *   2. 框架能自省「有没有注册、注册了几个」
 *   3. AI 可以通过 capabilities 看到「数据隔离已配置 / 未配置」
 *
 * 这样就把「不可见的业务约定」变成「框架可感知的声明」。
 */
class ScopeHooks
{
    /**
     * 已注册的作用域回调。
     *
     * @var array<string, callable(Builder): mixed>
     */
    protected static array $queryScopes = [];

    /**
     * 写入前回调（用于自动盖章，如设置 tenant_id）。
     *
     * @var array<string, callable(array<string, mixed>): array<string, mixed>>
     */
    protected static array $writeHooks = [];

    /**
     * 注册查询作用域 —— 对所有 Resource 查询生效。
     *
     * ```php
     * ScopeHooks::query('tenant', function (Builder $q) {
     *     $q->where('tenant_id', TenantContext::id());
     * });
     * ```
     *
     * @param  callable(Builder): mixed  $callback
     */
    public static function query(string $name, callable $callback): void
    {
        static::$queryScopes[$name] = $callback;
    }

    /**
     * 注册写入前钩子 —— 用于自动填充租户字段等。
     *
     * ```php
     * ScopeHooks::writing('tenant', function (array $data) {
     *     $data['tenant_id'] = TenantContext::id();
     *     return $data;
     * });
     * ```
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     */
    public static function writing(string $name, callable $callback): void
    {
        static::$writeHooks[$name] = $callback;
    }

    /**
     * 应用全部查询作用域。
     *
     * @param  Builder<Model>  $query
     */
    public static function applyQuery(Builder $query): void
    {
        foreach (static::$queryScopes as $callback) {
            $callback($query);
        }
    }

    /**
     * 应用全部写入钩子。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyWriting(array $data): array
    {
        foreach (static::$writeHooks as $callback) {
            $data = $callback($data);
        }

        return $data;
    }

    /**
     * 是否已配置查询作用域（供自省）。
     */
    public static function hasQueryScope(): bool
    {
        return static::$queryScopes !== [];
    }

    /**
     * 已注册的作用域名（供自省）。
     *
     * @return array<int, string>
     */
    public static function scopeNames(): array
    {
        return array_keys(static::$queryScopes);
    }

    /**
     * 自省信息 —— 让 AI 知道「数据隔离有没有配置」。
     *
     * @return array<string, mixed>
     */
    public static function introspect(): array
    {
        return [
            'query_scopes' => static::scopeNames(),
            'write_hooks' => array_keys(static::$writeHooks),
            'configured' => static::hasQueryScope(),
            'warning' => static::hasQueryScope()
                ? null
                : '⚠️ 未配置任何数据作用域 —— 所有 Resource 默认返回全表数据。'
                    .'若业务需要多租户/数据隔离，必须先注册 ScopeHooks::query()。',
        ];
    }

    /**
     * 清空（测试用）。
     */
    public static function flush(): void
    {
        static::$queryScopes = [];
        static::$writeHooks = [];
    }
}
