<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * Resource 契约。
 *
 * 一个 Resource = 一张数据表 = 一组后台页面（列表 + 表单 + 详情）。
 */
interface Resource
{
    /**
     * 资源唯一标识（用于 URI 与路由名），如 'users'。
     */
    public static function uri(): string;

    /**
     * 人类可读名称，如 '用户'。
     */
    public static function label(): string;

    /**
     * 绑定的 Eloquent 模型类名。
     *
     * @return class-string
     */
    public static function model(): string;
}
