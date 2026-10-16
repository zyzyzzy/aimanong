<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;

/**
 * 用了**死 prop** 的夹具 —— `ai:verify` 必须拦住它。
 *
 * 覆盖两侧与多种形态：
 *   - `relation`（列表列）
 *   - `allowHalf`、`separator`、`display`（表单字段）
 *
 * ⚠️ 这批 prop 会随「接通」而**减少** —— 2026-10-17 批 1 一次接通了 8 个
 * （placeholder / width / height / text / min / format / symbol / decimals），
 * 这个夹具就跟着换成了剩下 5 个。
 * 将来若某个也被接通，`PropConsumerTest` 会红，届时把这里一起改掉即可。
 */
class DeadPropResource extends Resource
{
    public static function model(): string
    {
        return \stdClass::class;
    }

    public static function label(): string
    {
        return '死 prop 夹具';
    }

    public static function uri(): string
    {
        return 'dead-prop-fixture';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID');
        $grid->column('owner', '负责人')->relation('owner.name');
    }

    public static function form(Form $form): void
    {
        $form->text('name')->label('名称');
        $form->rate('score')->allowHalf();
        $form->tags('labels')->separator('|');
        /*
         * `display` 这个 prop 没有公开 setter —— 它由 dateTime()/map()/enum()
         * 顺带下发。所以这里用 dateTime() 触发它。
         *
         * ⚠️ 注意：`format` 已接通（列表列按它渲染），但**表单字段**的
         * `display` 仍然没有任何消费方 —— 两者同源不同命。
         */
        $form->text('logged_at')->dateTime();
    }
}
