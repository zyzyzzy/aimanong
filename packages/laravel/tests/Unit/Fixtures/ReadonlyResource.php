<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;

/**
 * 只读 Resource 夹具（审计日志这类的替身）。
 */
class ReadonlyResource extends Resource
{
    public static function model(): string
    {
        return \stdClass::class;
    }

    public static function label(): string
    {
        return '只读演示';
    }

    public static function uri(): string
    {
        return 'readonly-demo';
    }

    public static function readonly(): bool
    {
        return true;
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
}
