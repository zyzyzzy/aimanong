<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;

/**
 * 只用**已接通** prop 的夹具 —— 反向对照。
 *
 * 用途：证明 `UNWIRED_PROP` 拦截**不会误报**。
 * 一个见谁都报的校验会被 AI 迅速学会忽略，反而比没有更糟。
 *
 * 这里刻意用上与死 prop **同族但已接通**的写法：
 *   - `help()`（对应死掉的 `placeholder()`）
 *   - `bool()` 的 `trueLabel/falseLabel`（已接通）
 *   - `progress()` / `map()` / `options()` / `rows()`
 *
 * ⚠️ 这里**不能**用 `->dateTime('Y-m-d')` —— 它除了 formatter 还会下发
 * `props.format`，而列上的 format **确实是死的**（前端 formatDate 写死
 * `slice(0,19)`）。用上它这个夹具就会被正确地拦下来，失去"反向对照"的意义。
 * 那条边界由 DeadPropResource 之外的用例覆盖。
 */
class WiredPropResource extends Resource
{
    public static function model(): string
    {
        return \stdClass::class;
    }

    public static function label(): string
    {
        return '已接通 prop 夹具';
    }

    public static function uri(): string
    {
        return 'wired-prop-fixture';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID')->sortable()->width(80);
        $grid->column('name', '名称')->searchable();
        $grid->column('active', '启用')->bool('开', '关');
        $grid->column('score', '评分')->progress();
        $grid->column('status', '状态')->badge()->map([1 => '启用', 0 => '停用']);
        $grid->column('amount', '金额')->money('¥');
        $grid->column('created_at', '创建时间')->dateTime('Y-m-d H:i');
        $grid->column('cover', '封面')->image(48);
        $grid->column('homepage', '主页')->link('去查看');
    }

    public static function form(Form $form): void
    {
        $form->text('name')->label('名称')->help('填完整名称')->placeholder('请输入名称');
        $form->textarea('note')->rows(6)->placeholder('补充说明');
        $form->select('status')->options(['a' => 'A', 'b' => 'B']);
        $form->number('sort')->rules(['nullable', 'numeric']);
        $form->money('price')->symbol('¥')->decimals(2);

        /*
         * ⚠️ 这一行是本夹具**最重要**的一条。
         *
         * `->relation()` 在**列**上确实是死的，但在**字段**上是接通了的
         * （Compiler 收进 meta.relationFields → Controller 拿它 sync 中间表）。
         *
         * 第一版实现只按 prop 名判断，于是把这种完全正常的写法也拦了 ——
         * 在 demo 上一次炸出 4 个假阳性。这条断言钉住「必须分位置」。
         */
        $form->multiSelect('roles')->relation('roles')->options(['a' => 'A']);
    }
}
