<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;

class ArticleResource extends Resource
{
    public static function model(): string
    {
        return \stdClass::class;
    }

    public static function label(): string
    {
        return '文章';
    }

    public static function uri(): string
    {
        return 'articles';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID')->sortable();
        $grid->column('title', '标题')->searchable();
        $grid->column('created_at', '创建时间')->dateTime();
    }

    public static function form(Form $form): void
    {
        $form->text('title')->label('标题')->required()->max(255);
        $form->textarea('body')->label('正文')->rows(10);
        $form->switch('published')->label('已发布');
    }
}
