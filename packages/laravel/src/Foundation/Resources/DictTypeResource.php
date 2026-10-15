<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Resources;

use Aimanong\Contracts\ApplicationResolver;
use Aimanong\Form\Form;
use Aimanong\Foundation\Dict\DictType;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Show\Show;

/**
 * 数据字典分类（内置 Resource）。
 *
 * 后台可维护的字典分类。代码声明的字典（config 里的 declarations）
 * 也会出现在这里，但标记为「代码声明」且不可删改 ——
 * 否则运维删掉一个被代码引用的字典，相关页面立刻变成空下拉框。
 */
class DictTypeResource extends Resource
{
    public static function model(): string
    {
        return DictType::class;
    }

    public static function label(): string
    {
        return '数据字典';
    }

    public static function uri(): string
    {
        return 'admin-dict-types';
    }

    public static function menu(): array
    {
        return ['group' => '系统', 'icon' => '📖', 'sort' => 93];
    }

    /**
     * 只属于总管理后台。
     *
     * 内置的系统管理类（管理员/角色/权限/日志/字典）属于框架运维面，
     * 不该出现在用户后台或代理商后台 —— 菜单串台等于把后台结构
     * 暴露给不该看的人。
     */
    public static function applications(): array
    {
        return [ApplicationResolver::PRIMARY];
    }

    public static function grid(Grid $grid): void
    {
        $grid->perPage(20);

        $grid->column('id', 'ID')->sortable();
        $grid->column('code', '字典 code')->searchable();
        $grid->column('name', '名称')->searchable();
        $grid->column('description', '说明');
        $grid->column('is_locked', '来源')->bool('代码声明', '后台维护');
        $grid->column('sort', '排序')->sortable();
        $grid->column('updated_at', '更新时间')->dateTime()->sortable();
    }

    public static function form(Form $form): void
    {
        $form->text('code')->label('字典 code')->required()->max(100)
            ->help('代码里用这个名字引用，如 order_status。建议小写下划线。');
        $form->text('name')->label('名称')->required()->max(190);
        $form->textarea('description')->label('说明')->rows(3);
        $form->number('sort')->label('排序')->default(0);
    }

    public static function show(Show $show): void
    {
        $show->fields(['id', 'code', 'name', 'description', 'is_locked', 'sort', 'created_at', 'updated_at']);
    }
}
