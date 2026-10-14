<?php

declare(strict_types=1);

namespace Aimanong\Support;

use Aimanong\Contracts\ApplicationResolver;
use Aimanong\Contracts\DictionarySource;
use Aimanong\Contracts\PermissionChecker;
use Aimanong\Contracts\SchemaIntrospector;
use Aimanong\Contracts\UploadSpec;
use Aimanong\Exceptions\DriverNotRegisteredException;

/**
 * 驱动注册表 —— core 与「具体框架」之间的**唯一接缝**。
 *
 * ## 为什么需要它
 *
 * 多框架适配的障碍不在接口层，而在依赖层：core 里任何一句
 * `new Dictionary` / `PermissionGate::check()` / `new Uploader`
 * 都会把 core 绑死到 Laravel 包上。有了注册表，
 * core 只说「我需要一个 DictionarySource」，谁来提供由框架包决定。
 *
 * ## 两类缺失，处理方式**故意不同**
 *
 * | 契约 | 缺失时 | 理由 |
 * |---|---|---|
 * | DictionarySource / PermissionChecker / UploadSpec | **抛异常** | 用户**声明**了 `->dict()` / 菜单权限 / 上传字段。声明了却兑现不了，就是幽灵能力（宪法第三条）。宁可当场报错。 |
 * | SchemaIntrospector | 返回 null，调用方跳过检测 | 幽灵列检测是**尽力而为**的编译期检查，本来就在无数据库时跳过。 |
 *
 * 这个区分很重要：前者是「声明成了谎言」，后者是「这项检查做不了」。
 * 两者混为一谈会导致要么无谓报错，要么静默产出错误 schema。
 */
final class Drivers
{
    private static ?DictionarySource $dictionary = null;

    private static ?PermissionChecker $permissions = null;

    private static ?UploadSpec $upload = null;

    private static ?SchemaIntrospector $introspector = null;

    private static ?ApplicationResolver $application = null;

    /** 禁止实例化 —— 纯注册表 */
    private function __construct() {}

    // ─────────────────────────────────────────────
    // 数据字典
    // ─────────────────────────────────────────────

    public static function registerDictionary(DictionarySource $driver): void
    {
        self::$dictionary = $driver;
    }

    public static function hasDictionary(): bool
    {
        return self::$dictionary !== null;
    }

    public static function dictionary(): DictionarySource
    {
        return self::$dictionary ?? throw new DriverNotRegisteredException(
            'DictionarySource',
            '数据字典（Form::dict() / Column::dict()）',
            self::registeredNames()
        );
    }

    // ─────────────────────────────────────────────
    // 权限判定
    // ─────────────────────────────────────────────

    public static function registerPermissionChecker(PermissionChecker $driver): void
    {
        self::$permissions = $driver;
    }

    public static function hasPermissionChecker(): bool
    {
        return self::$permissions !== null;
    }

    public static function permissions(): PermissionChecker
    {
        return self::$permissions ?? throw new DriverNotRegisteredException(
            'PermissionChecker',
            '菜单权限过滤',
            self::registeredNames()
        );
    }

    // ─────────────────────────────────────────────
    // 上传能力描述
    // ─────────────────────────────────────────────

    public static function registerUploadSpec(UploadSpec $driver): void
    {
        self::$upload = $driver;
    }

    public static function hasUploadSpec(): bool
    {
        return self::$upload !== null;
    }

    public static function upload(): UploadSpec
    {
        return self::$upload ?? throw new DriverNotRegisteredException(
            'UploadSpec',
            '上传字段（产物里的上传能力描述）',
            self::registeredNames()
        );
    }

    // ─────────────────────────────────────────────
    // Schema 内省（可缺 —— 见类注释里的区分）
    // ─────────────────────────────────────────────

    // ─────────────────────────────────────────────
    // 多应用（多后台）
    // ─────────────────────────────────────────────

    public static function registerApplicationResolver(ApplicationResolver $driver): void
    {
        self::$application = $driver;
    }

    public static function hasApplicationResolver(): bool
    {
        return self::$application !== null;
    }

    /**
     * 当前应用解析器。
     *
     * ⚠️ 与其他驱动**唯一不同**的地方：它**不抛**
     * `DriverNotRegisteredException`，而是回退到 `SingleApplication`。
     *
     * 为什么：单应用（只有一个后台）是**默认状态**，不是「缺了什么」。
     * 没注册解析器 ≠ 框架坏了，只是用户没开多应用。
     * 让所有按应用过滤的代码无需分叉 —— 单应用就是「只有一个应用的多应用」。
     */
    public static function application(): ApplicationResolver
    {
        return self::$application ?? new SingleApplication;
    }

    public static function registerIntrospector(SchemaIntrospector $driver): void
    {
        self::$introspector = $driver;
    }

    public static function hasIntrospector(): bool
    {
        return self::$introspector !== null;
    }

    /**
     * 拿内省实现；**没注册时返回 null**，由调用方决定跳过还是报错。
     *
     * 这里不抛异常是刻意的：幽灵列检测在无数据库环境下本就跳过，
     * 抛异常会让纯静态自省（如产物生成）整体不可用。
     */
    public static function introspector(): ?SchemaIntrospector
    {
        return self::$introspector;
    }

    // ─────────────────────────────────────────────
    // 自省与测试隔离
    // ─────────────────────────────────────────────

    /**
     * 已注册的驱动名 —— 供错误信息使用，让 AI 一眼看出缺什么。
     *
     * @return array<int, string>
     */
    public static function registeredNames(): array
    {
        $names = [];

        if (self::$introspector !== null) {
            $names[] = 'SchemaIntrospector';
        }

        if (self::$dictionary !== null) {
            $names[] = 'DictionarySource';
        }

        if (self::$permissions !== null) {
            $names[] = 'PermissionChecker';
        }

        if (self::$upload !== null) {
            $names[] = 'UploadSpec';
        }

        if (self::$application !== null) {
            $names[] = 'ApplicationResolver';
        }

        return $names;
    }

    /**
     * 清空全部驱动（测试用）。
     */
    public static function reset(): void
    {
        self::$dictionary = null;
        self::$permissions = null;
        self::$upload = null;
        self::$introspector = null;
        self::$application = null;
    }
}
