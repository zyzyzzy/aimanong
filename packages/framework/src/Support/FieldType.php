<?php

declare(strict_types=1);

namespace Aimanong\Support;

/**
 * 字段类型注册表。
 *
 * Aimanong 设计：所有可用字段类型必须在此登记，
 * 使 /__ai/capabilities.json 能完整枚举 —— AI 不需要猜。
 */
class FieldType
{
    /**
     * 类型定义表：类型名 → JSON Schema 类型 + 额外属性。
     *
     * @var array<string, array{json: string, php: string}>
     */
    protected const TYPES = [
        // 文本类
        'text' => ['json' => 'string', 'php' => 'string'],
        'textarea' => ['json' => 'string', 'php' => 'string'],
        'email' => ['json' => 'string', 'php' => 'string'],
        'url' => ['json' => 'string', 'php' => 'string'],
        'password' => ['json' => 'string', 'php' => 'string'],
        'tel' => ['json' => 'string', 'php' => 'string'],

        // 数值类
        'number' => ['json' => 'integer', 'php' => 'int'],
        'decimal' => ['json' => 'number', 'php' => 'float'],
        'money' => ['json' => 'number', 'php' => 'float'],
        'rate' => ['json' => 'integer', 'php' => 'int'],
        'slider' => ['json' => 'integer', 'php' => 'int'],

        // 选择类
        'select' => ['json' => 'string', 'php' => 'string'],
        'multiselect' => ['json' => 'array', 'php' => 'array'],
        'radio' => ['json' => 'string', 'php' => 'string'],
        'checkbox' => ['json' => 'array', 'php' => 'array'],
        'switch' => ['json' => 'boolean', 'php' => 'bool'],

        // 日期类
        'date' => ['json' => 'string', 'php' => 'string'],
        'datetime' => ['json' => 'string', 'php' => 'string'],
        'time' => ['json' => 'string', 'php' => 'string'],
        'daterange' => ['json' => 'array', 'php' => 'array'],

        // 上传类
        'image' => ['json' => 'string', 'php' => 'string'],
        'images' => ['json' => 'array', 'php' => 'array'],
        'file' => ['json' => 'string', 'php' => 'string'],
        'files' => ['json' => 'array', 'php' => 'array'],

        // 其它
        'color' => ['json' => 'string', 'php' => 'string'],
        'icon' => ['json' => 'string', 'php' => 'string'],
        'tags' => ['json' => 'array', 'php' => 'array'],
        'hidden' => ['json' => 'string', 'php' => 'string'],
        'display' => ['json' => 'string', 'php' => 'mixed'],
        'divider' => ['json' => 'null', 'php' => 'mixed'],
    ];

    /**
     * 运行期注册的字段类型（供插件注入）。
     *
     * @var array<string, array{json: string, php: string}>
     */
    protected static array $registered = [];

    /**
     * 已注册的字段类（供 Form 动态创建实例）。
     *
     * @var array<string, string>
     */
    protected static array $fieldClasses = [];

    /**
     * 字段的**渲染片段**（blade 视图名）。
     *
     * ## 为什么需要这个
     *
     * 插件注册了字段类型（`Extension::field()`）之后，只能表达
     * 「这个字段是什么类型」，**无法表达「它长什么样」** ——
     * 渲染逻辑只能写在框架核心的 resource.blade.php 里。
     *
     * 后果（真实发生）：官方 region 插件的三级联动 UI 是硬编码在
     * **框架核心模板**里的，而 framework 的 composer.json 并没有
     * 依赖 region —— 只装核心包的用户打开列表页直接 Fatal error。
     *
     * 这不只是耦合问题：**第三方插件根本没有出路** ——
     * 想加个自定义字段，除了改框架源码别无他法。
     *
     * @var array<string, string>
     */
    protected static array $fieldViews = [];

    /**
     * 注册一个字段类型。
     *
     * 供插件注入自定义字段 —— 无需修改框架核心。
     *
     * @param  string  $name  类型名（如 region）
     * @param  string  $jsonType  对应的 JSON Schema 类型
     * @param  string  $phpType  对应的 PHP 类型（用于 TS 生成）
     */
    public static function register(
        string $name,
        string $jsonType = 'string',
        string $phpType = 'string',
        ?string $fieldClass = null,
    ): void {
        self::$registered[$name] = ['json' => $jsonType, 'php' => $phpType];

        if ($fieldClass !== null) {
            self::$fieldClasses[$name] = $fieldClass;
        }
    }

    /**
     * 注册字段的渲染片段。
     *
     * 插件侧：
     *
     * ```php
     * $this->views(__DIR__.'/../resources/views', 'aimanong-region');
     * $this->fieldView('region', 'aimanong-region::field');
     * ```
     *
     * 片段里可使用 `f`（字段定义）、`form`（表单值）、`uri` 等变量，
     * 与框架内置字段分支处于同一作用域 —— 插件因此能写出
     * 与内置字段完全一致的自定义 UI。
     *
     * @param  string  $type  字段类型名（与 Extension::field() 注册的一致）
     * @param  string  $view  blade 视图名
     */
    public static function fieldView(string $type, string $view): void
    {
        self::$fieldViews[$type] = $view;
    }

    /**
     * 取字段的渲染片段（未注册则返回 null，由框架走内置分支）。
     */
    public static function fieldViewFor(string $type): ?string
    {
        return self::$fieldViews[$type] ?? null;
    }

    /**
     * 字段用到的 **JS 片段**（注入到页面 setup() 作用域内）。
     *
     * @var array<int, string>
     */
    protected static array $fieldScripts = [];

    /**
     * 注册字段的 JS 片段。
     *
     * 片段在 Vue 的 `setup()` **作用域内**渲染，因此可以直接用
     * `ref` / `computed` / `form` 等，并把辅助函数挂到 `pluginExports`：
     *
     * ```js
     * // 插件片段（示意）
     * const myState = ref({});
     * function myHelper(name) { return myState.value[name]; }
     * Object.assign(pluginExports, { myHelper });
     * ```
     *
     * 框架最后会把这些导出合并进 setup 的返回值，
     * 模板因此可以直接调用插件函数 —— **与内置字段同等待遇**。
     */
    public static function fieldScript(string $view): void
    {
        if (! in_array($view, self::$fieldScripts, true)) {
            self::$fieldScripts[] = $view;
        }
    }

    /**
     * 全部字段 JS 片段。
     *
     * @return array<int, string>
     */
    public static function fieldScripts(): array
    {
        return self::$fieldScripts;
    }

    /**
     * 全部已注册渲染片段的字段类型。
     *
     * @return array<int, string>
     */
    public static function viewableTypes(): array
    {
        return array_keys(self::$fieldViews);
    }

    /**
     * 取字段类名。
     */
    public static function fieldClass(string $type): ?string
    {
        return self::$fieldClasses[$type] ?? null;
    }

    /**
     * 是否由插件注册（用于区分内置与扩展类型）。
     */
    public static function isRegistered(string $type): bool
    {
        return isset(self::$registered[$type]);
    }

    /**
     * 全部可用类型名（内置 + 插件注册）。
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(array_keys(self::TYPES), array_keys(self::$registered));
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]) || isset(self::$registered[$type]);
    }

    /**
     * 映射到 JSON Schema 类型。
     */
    public static function toJsonType(string $type): string
    {
        return self::TYPES[$type]['json']
            ?? self::$registered[$type]['json']
            ?? 'string';
    }

    /**
     * 映射到 PHP 类型（用于 TS 类型生成）。
     */
    public static function toPhpType(string $type): string
    {
        return self::TYPES[$type]['php']
            ?? self::$registered[$type]['php']
            ?? 'string';
    }

    /**
     * 模糊匹配：AI 打错字时给出建议。
     *
     * 这是"可自愈错误"的基础设施。
     */
    public static function suggest(string $input): ?string
    {
        if (self::exists($input)) {
            return $input;
        }

        $best = null;
        $shortest = -1;

        foreach (self::all() as $type) {
            $lev = levenshtein($input, $type);

            // 距离小于 3 才认为是拼写错误
            if ($lev <= 2 && ($lev < $shortest || $shortest < 0)) {
                $best = $type;
                $shortest = $lev;
            }
        }

        return $best;
    }
}
