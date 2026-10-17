<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;

/**
 * 声明了 `cards` 展示形态的夹具。
 *
 * 用途：验证 `mode()` 从声明一路走到页面 schema 与自省接口。
 */
class CardsModeResource extends Resource
{
    public static function model(): string
    {
        return \stdClass::class;
    }

    public static function label(): string
    {
        return '卡片形态夹具';
    }

    public static function uri(): string
    {
        return 'cards-mode-fixture';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID');
        $grid->column('cover', '封面')->image(48);
        $grid->column('title', '标题')->searchable();

        $grid->mode('cards');
    }

    public static function form(Form $form): void
    {
        $form->text('title')->label('标题');
    }
}
