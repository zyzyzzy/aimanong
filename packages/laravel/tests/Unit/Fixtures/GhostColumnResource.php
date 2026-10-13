<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit\Fixtures;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;

/**
 * 用于幽灵列检测测试的 Resource。
 *
 * 表结构（由 FakeIntrospector 提供）：id / title / created_at
 * 但声明里写了 `titel` —— 一个真实存在过的 AI 拼写错误形态。
 */
class GhostColumnResource extends Resource
{
    public static function model(): string
    {
        // 只要是个可实例化的类即可，检测所需的事实全部来自内省契约
        return \stdClass::class;
    }

    public static function label(): string
    {
        return '幽灵列测试';
    }

    public static function uri(): string
    {
        return 'ghost-columns';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID');
        $grid->column('titel', '标题');        // ← 拼错：应为 title
        $grid->column('created_at', '创建时间');
    }

    public static function form(Form $form): void
    {
        $form->text('title')->label('标题');
    }
}
