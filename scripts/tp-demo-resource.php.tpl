<?php

declare(strict_types=1);

namespace app;

use Aimanong\Resource;

/**
 * 与 Laravel 侧**同一套声明写法** —— 这正是多框架适配的意义：
 * grid/form/show 是框架无关的，只有注册那一行是框架胶水。
 */
class CustomerResource extends Resource
{
    public static function model(): string
    {
        return Customer::class;
    }

    public static function label(): string
    {
        return '客户';
    }

    public static function uri(): string
    {
        return 'customers';
    }

    public static function grid(\Aimanong\Grid\Grid $grid): void
    {
        $grid->column('id', 'ID')->sortable();
        $grid->column('name', '名称')->searchable();
        $grid->column('industry', '行业');
        $grid->column('level', '等级')->dict('priority');
    }

    public static function form(\Aimanong\Form\Form $form): void
    {
        $form->text('name')->label('名称')->required();
        $form->text('industry')->label('行业');
        $form->text('level')->label('等级');
    }
}
