<?php

declare(strict_types=1);

namespace app;

use Aimanong\Resource;

/**
 * 只读资源 —— e2e 用它验证「只读资源不能被写」。
 *
 * 这是本次全面验收发现的高危缺口之一：TP 侧此前完全没有只读保护，
 * 而共享的 OpenApiEmitter 按 readonly 省略了写接口 ——
 * 产物说不许写、接口却接受写。
 */
class ReadonlyCustomerResource extends Resource
{
    public static function model(): string
    {
        return Customer::class;
    }

    public static function label(): string
    {
        return '只读客户';
    }

    public static function uri(): string
    {
        return 'readonly-customers';
    }

    public static function readonly(): bool
    {
        return true;
    }

    public static function grid(\Aimanong\Grid\Grid $grid): void
    {
        $grid->column('id', 'ID');
        $grid->column('name', '名称');
    }

    public static function form(\Aimanong\Form\Form $form): void
    {
        $form->text('name')->label('名称');
    }
}
