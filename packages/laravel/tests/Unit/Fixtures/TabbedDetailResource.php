<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Layout\Layout;
use Aimanong\Resource;
use Aimanong\Show\Show;

/**
 * 声明了**标签页详情页**的夹具。
 *
 * 覆盖 `show()` 与 `layout()` 的配合：字段在 show() 里声明，
 * 结构在 layout() 里分组。
 */
class TabbedDetailResource extends Resource
{
    public static function model(): string
    {
        return \stdClass::class;
    }

    public static function label(): string
    {
        return '标签页详情夹具';
    }

    public static function uri(): string
    {
        return 'tabbed-detail-fixture';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID');
        $grid->column('name', '名称');
    }

    public static function form(Form $form): void
    {
        $form->text('name')->label('名称');
    }

    public static function show(Show $show): void
    {
        $show->fields(['id', 'name', 'phone', 'created_at']);
    }

    public static function layout(Layout $layout): void
    {
        $layout->tabs([
            '基本' => ['id', 'name'],
            '联系' => ['phone', 'created_at'],
        ]);
    }
}
