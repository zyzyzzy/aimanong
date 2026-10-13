<?php

declare(strict_types=1);

namespace Aimanong\Extend;

use Aimanong\Aimanong;
use Aimanong\Application\ApplicationContext;
use Aimanong\Contracts\Resource;
use Aimanong\Support\FieldType;
use Illuminate\Support\Facades\Route;

/**
 * 扩展（插件）基类。
 *
 * 一个扩展 = 一个 Composer 包，通过继承本类把能力注入框架。
 *
 * 设计原则：
 *   1. 扩展只依赖公开 API，不碰框架内部
 *   2. 生命周期明确：register() → boot() → 销毁
 *   3. 失败隔离：单个扩展出错不应拖垮整个后台
 */
abstract class Extension
{
    /**
     * 扩展唯一标识（用于启用/禁用与冲突检测）。
     */
    abstract public function name(): string;

    /**
     * 人类可读名称。
     */
    public function title(): string
    {
        return $this->name();
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return '';
    }

    /**
     * 依赖的其他扩展名。
     *
     * @return array<int, string>
     */
    public function dependencies(): array
    {
        return [];
    }

    /**
     * 注册阶段：绑定容器、注册 Resource。
     *
     * 此时**不要**访问数据库或渲染视图 —— 应用可能尚未完全启动。
     */
    public function register(): void
    {
        // 子类按需覆盖
    }

    /**
     * 启动阶段：注册路由、菜单、视图。
     *
     * 此时应用已完全启动，可安全访问容器与配置。
     */
    public function boot(): void
    {
        // 子类按需覆盖
    }

    /**
     * 注册 Resource。
     *
     * @param  class-string<\Aimanong\Contracts\Resource>  ...$resources
     */
    protected function resources(string ...$resources): void
    {
        foreach ($resources as $resource) {
            Aimanong::registry()->register($resource);
        }
    }

    /**
     * 注册后台路由（自动加上后台前缀与中间件）。
     *
     * @param  \Closure():void  $callback
     */
    protected function routes(\Closure $callback): void
    {
        // 走 context 而非 config —— 多应用下扩展路由也要挂到正确的后台
        /** @var ApplicationContext $context */
        $context = Aimanong::context();
        $prefix = $context->prefix();

        Route::middleware('admin')
            ->prefix($prefix.'/extensions/'.$this->name())
            ->name('aimanong.ext.'.$this->name().'.')
            ->group($callback);
    }

    /**
     * 注册自定义字段类型。
     *
     * 供插件注入字段能力，无需修改框架核心。
     *
     * 注意：字段类的 `$type` 属性值必须与这里注册的类型名一致。
     *
     * @param  class-string  $fieldClass  字段类（继承 Aimanong\Form\Fields\Field）
     * @param  string|null  $type  类型名，省略时由字段类推导
     * @param  string  $jsonType  JSON Schema 类型
     * @param  string  $phpType  PHP 类型（用于 TS 生成）
     */
    protected function field(
        string $fieldClass,
        ?string $type = null,
        string $jsonType = 'string',
        string $phpType = 'string',
    ): void {
        $type ??= $this->deriveTypeFrom($fieldClass);

        if ($type === null || $type === '') {
            throw new \InvalidArgumentException(
                "无法从 {$fieldClass} 推导字段类型名，请显式传入 \$type"
            );
        }

        FieldType::register($type, $jsonType, $phpType, $fieldClass);
    }

    /**
     * 注册字段的**渲染片段** —— 让插件自带前端 UI。
     *
     * ## 为什么需要它
     *
     * `field()` 只解决「这个字段是什么类型」，不解决「它长什么样」。
     * 在此之前，插件字段的渲染逻辑只能写在框架核心的模板里 ——
     * 那意味着第三方插件想加自定义字段，**除了改框架源码别无他法**。
     *
     * ## 用法
     *
     * ```php
     * public function boot(): void
     * {
     *     // 1. 注册视图命名空间（指向插件自己的 resources/views）
     *     $this->views(__DIR__.'/../resources/views', 'aimanong-region');
     *
     *     // 2. 把字段类型绑到片段上
     *     $this->fieldView('region', 'aimanong-region::field');
     * }
     * ```
     *
     * 片段在**表单字段的作用域内**渲染，可直接使用：
     *   - `$f`    字段定义（name / label / props ...）
     *   - `$uri`  当前 Resource 的 uri
     * 以及页面已注入的 Vue 上下文（`form`、`fieldOptions` 等）。
     *
     * @param  string  $type  字段类型名，须与 field() 注册的一致
     * @param  string  $view  blade 视图名（含命名空间）
     */
    protected function fieldView(string $type, string $view): void
    {
        FieldType::fieldView($type, $view);
    }

    /**
     * 注册字段用到的 JS 片段（注入到 Vue setup() 作用域内）。
     *
     * 与 fieldView() 配对使用：前者管「长什么样」，这里管「逻辑怎么算」。
     *
     * 片段里可以直接用 `ref` / `computed` / `form` / `f`（字段定义），
     * 通过 `Object.assign(pluginExports, {...})` 把函数暴露给模板。
     */
    protected function fieldScript(string $view): void
    {
        FieldType::fieldScript($view);
    }

    /**
     * 从字段类的 $type 属性推导类型名。
     *
     * @param  class-string  $fieldClass
     */
    protected function deriveTypeFrom(string $fieldClass): ?string
    {
        if (! class_exists($fieldClass)) {
            return null;
        }

        try {
            $ref = new \ReflectionClass($fieldClass);

            if (! $ref->hasProperty('type')) {
                return null;
            }

            $prop = $ref->getProperty('type');
            $prop->setAccessible(true);

            // 无法在不实例化的情况下读实例属性 —— 用默认值读取
            $defaults = $ref->getDefaultProperties();
            $value = $defaults['type'] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 注册扩展自己的视图命名空间。
     */
    protected function views(string $path, ?string $namespace = null): void
    {
        $namespace ??= 'aimanong-ext-'.$this->name();

        app('view')->addNamespace($namespace, $path);
    }

    /**
     * 注册翻译文件。
     */
    protected function translations(string $path, ?string $namespace = null): void
    {
        $namespace ??= 'aimanong-ext-'.$this->name();

        app('translator')->addNamespace($namespace, $path);
    }

    /**
     * 扩展元信息。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'version' => $this->version(),
            'description' => $this->description(),
            'dependencies' => $this->dependencies(),
            'class' => static::class,
        ];
    }
}
