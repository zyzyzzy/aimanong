<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Contracts\Resource as ResourceContract;
use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Show\Show;
use Aimanong\Tree\Tree;

/**
 * Resource 基类。
 *
 * 用户继承此类，实现 grid() / form() / show() 三个静态方法，
 * 框架自动产出：REST API、前端页面、JSON Schema、
 * TypeScript 类型、OpenAPI 文档、AI 提示词。
 *
 * Aimanong 约定：三个方法都是静态的、签名唯一。
 */
abstract class Resource implements ResourceContract
{
    /**
     * 列表页定义。子类覆盖。
     */
    public static function grid(Grid $grid): void
    {
        // 默认不定义列，子类覆盖
    }

    /**
     * 表单页定义。子类覆盖。
     */
    public static function form(Form $form): void
    {
        // 默认不定义字段，子类覆盖
    }

    /**
     * 详情页定义。子类覆盖。
     */
    public static function show(Show $show): void
    {
        // 默认不定义字段，子类覆盖
    }

    /**
     * 树形结构定义。子类覆盖以启用树形页面。
     *
     * 用法：$tree->parentColumn('parent_id')->titleColumn('name');
     */
    public static function tree(Tree $tree): void
    {
        // 默认不启用树形，子类覆盖
    }

    /**
     * 资源 URI，默认按类名推导（UserResource → users）。
     */
    public static function uri(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();
        $name = str_ends_with($short, 'Resource')
            ? substr($short, 0, -strlen('Resource'))
            : $short;

        return strtolower($name).'s';
    }

    /**
     * 人类可读名称，默认用 URI。
     */
    public static function label(): string
    {
        return static::uri();
    }

    /**
     * 绑定模型，子类必须覆盖。
     */
    public static function model(): string
    {
        throw new \RuntimeException(
            'Resource ['.static::class.'] 必须实现 model() 方法，返回 Eloquent 模型类名。'
        );
    }
}
