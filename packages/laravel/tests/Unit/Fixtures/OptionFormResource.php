<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;

/**
 * 选项类字段的契约夹具。
 *
 * 专门覆盖 select / multiSelect / radio 的「选项形状 + 默认值」，
 * 用于 FormOptionContractTest 锁定前端渲染所依赖的数据结构。
 */
class OptionFormResource extends Resource
{
    public static function model(): string
    {
        return \stdClass::class;
    }

    public static function label(): string
    {
        return '选项契约';
    }

    public static function uri(): string
    {
        return 'option-contract';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID');
        $grid->column('name', '名称');
    }

    public static function form(Form $form): void
    {
        $form->text('name')->label('名称')->required();
        $form->select('status')->label('状态')->options([
            'draft' => '草稿',
            'published' => '已发布',
        ])->default('draft');
        $form->multiSelect('tags')->label('标签')->options([
            1 => 'Laravel',
            2 => 'Vue',
        ]);
    }
}
