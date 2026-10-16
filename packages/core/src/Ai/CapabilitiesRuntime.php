<?php

declare(strict_types=1);

namespace Aimanong\Ai;

use Aimanong\Support\PermissionNodes;

/**
 * 能力清单的**驱动侧输入**。
 *
 * ## 为什么需要它
 *
 * `Capabilities` 有 687 行，但其中 **600 多行是「这套声明怎么用」的
 * 说明文本** —— 字段类型、列选项、校验规则、树选项、HTTP 约定……
 * 这些东西**两端的答案完全一样**（它们描述的是声明层，不是实现）。
 *
 * 只有 5 个点是真的「只有驱动才知道」：
 *
 * | 点 | Laravel | ThinkPHP |
 * |---|---|---|
 * | 配置开关 | `config()` | `Config::get()` |
 * | 框架版本 | `app()->version()` | `App::version()` |
 * | 字典列表 | 数据库字典（可后台维护） | 内置声明的字典 |
 * | RBAC 开关 | `aimanong.auth.rbac` | 同左，各读各的配置 |
 * | RBAC 动作表 | `PermissionNodes::ACTIONS`（**core**） | 同左 |
 *
 * 所以把 `Capabilities` 放进 core、把这 5 个点从这里取 ——
 * 而不是在两个驱动里各抄一份 687 行的说明文本。
 *
 * ## 抄一份的后果（这不是假想）
 *
 * 本轮审计抓到的最贵的一类 bug 就是「同一份声明两处各写一遍」：
 * 跨应用访问一处返回 403、另一处 404；MCP 工具名 103 处写错；
 * `IMPLEMENTED` 常量和类注释对不上。两处文本必然漂移，
 * 而漂移的那一侧没有任何机制会告诉你。
 *
 * ## 未注册时的行为
 *
 * 全部退化为**安全默认值**（开关取传入的默认、版本为空串、
 * 字典为空数组）。这样纯静态自省、单元测试、CLI 早期都能读，
 * 不会因为「容器还没起来」就抛异常。
 */
final class CapabilitiesRuntime
{
    /**
     * @var (callable(string, mixed): mixed)|null
     */
    private static $configReader = null;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $dictionaries = null;

    private static ?bool $rbacEnabled = null;

    /**
     * @var array<int, string>|null
     */
    private static ?array $rbacActions = null;

    /**
     * 注册配置读取器。
     *
     * @param  callable(string, mixed): mixed  $reader
     */
    public static function provideConfigReader(callable $reader): void
    {
        self::$configReader = $reader;
    }

    /**
     * 注册框架版本（键 = 驱动名，如 laravel / thinkphp）。
     */
    public static function provideFrameworkVersion(string $driver, string $version): void
    {
        self::$frameworkVersions[$driver] = $version;
    }

    /**
     * 注册字典清单（只有驱动知道怎么取）。
     *
     * @param  array<string, mixed>  $dictionaries
     */
    public static function provideDictionaries(array $dictionaries): void
    {
        self::$dictionaries = $dictionaries;
    }

    /**
     * 注册 RBAC 状态。
     *
     * @param  array<int, string>|null  $actions  不传则用 core 的 PermissionNodes::ACTIONS
     */
    public static function provideRbac(bool $enabled, ?array $actions = null): void
    {
        self::$rbacEnabled = $enabled;
        self::$rbacActions = $actions;
    }

    /**
     * @var array<string, string>
     */
    private static array $frameworkVersions = [];

    /**
     * 读布尔配置；无驱动时退化为默认值。
     */
    public static function flag(string $key, bool $default = true): bool
    {
        if (self::$configReader === null) {
            return $default;
        }

        try {
            return (bool) (self::$configReader)($key, $default);
        } catch (\Throwable) {
            // 兜住「容器还没起来」—— 只对展示性开关容错，
            // 业务逻辑里的 config() 不许这么写（那会掩盖真实配置错误）
            return $default;
        }
    }

    /**
     * 驱动版本表，形如 ['laravel' => '12.0.0'] 或 ['thinkphp' => '8.0.0']。
     *
     * @return array<string, string>
     */
    public static function frameworkVersions(): array
    {
        return self::$frameworkVersions;
    }

    /**
     * @return array<string, mixed>
     */
    public static function dictionaryList(): array
    {
        return self::$dictionaries ?? [];
    }

    public static function rbacEnabled(): bool
    {
        return self::$rbacEnabled ?? false;
    }

    /**
     * @return array<int, string>
     */
    public static function rbacActions(): array
    {
        return self::$rbacActions ?? PermissionNodes::ACTIONS;
    }

    /**
     * 复位（单元测试用 —— 静态状态会跨用例泄漏）。
     */
    public static function reset(): void
    {
        self::$configReader = null;
        self::$frameworkVersions = [];
        self::$dictionaries = null;
        self::$rbacEnabled = null;
        self::$rbacActions = null;
    }
}
