<?php

declare(strict_types=1);

namespace App\Aimanong;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Show\Show;
use App\Models\Product;

class ProductResource extends Resource
{
    public static function model(): string
    {
        return Product::class;
    }

    public static function label(): string
    {
        return '商品';
    }

    public static function uri(): string
    {
        return 'products';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID')->sortable();
        $grid->column('title', '标题')->searchable();
        $grid->column('price', '价格')->sortable();
        $grid->column('stock', '库存')->sortable();
        $grid->column('status', '状态')->searchable();
        $grid->column('published', '是否上架');
        $grid->column('created_at', '创建时间')->dateTime()->sortable();
        $grid->column('updated_at', '更新时间')->dateTime()->sortable();
    }

    public static function form(Form $form): void
    {
        $form->text('title')->label('标题')->required()->max(255);
        $form->decimal('price')->label('价格')->decimals(2);
        $form->number('stock')->label('库存')->default(0);
        $form->text('status')->label('状态')->max(255);
        $form->switch('published')->label('是否上架');
    }

    public static function show(Show $show): void
    {
        $show->fields(['id', 'title', 'price', 'stock', 'status', 'published']);
    }
}
