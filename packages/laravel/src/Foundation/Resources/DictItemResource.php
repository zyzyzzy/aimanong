<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Resources;

use Aimanong\Form\Form;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Foundation\Dict\DictItem;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Show\Show;

/**
 * 数据字典条目（内置 Resource）。
 *
 * `type_code` 用字符串而非外键 id：读取方按 code 查，
 * 分类被误删时条目不会变成孤儿，只是查不到（返回空），不会崩。
 */
class DictItemResource extends Resource
{
    public static function model(): string
    {
        return DictItem::class;
    }

    public static function label(): string
    {
        return '字典条目';
    }

    public static function uri(): string
    {
        return 'admin-dict-items';
    }

    public static function menu(): array
    {
        return ['group' => '系统', 'icon' => '🏷️', 'sort' => 94];
    }

    public static function grid(Grid $grid): void
    {
        $grid->perPage(20);

        $grid->column('id', 'ID')->sortable();
        $grid->column('type_code', '所属字典')->searchable();
        $grid->column('value', '值')->searchable();
        $grid->column('label', '文案')->searchable();
        // 存的是英文语义色，列表显示中文（词表来自模型，单一来源）
        $grid->column('color', '徽章颜色')->map(DictItem::colorOptions());
        $grid->column('sort', '排序')->sortable();
        $grid->column('enabled', '启用')->bool('启用', '停用');
    }

    public static function form(Form $form): void
    {
        /*
         * 所属字典用 select 而不是 dict() —— 字典列表本身来自这张表，
         * 用 dict() 会指向另一个字典，语义上说不通。
         */
        $form->select('type_code')->label('所属字典')->options(self::typeOptions())->required();
        $form->text('value')->label('值')->required()->max(190)
            ->help('存进数据库的原始值，如 pending。建好后不建议再改 —— 已存数据不会跟着变。');
        $form->text('label')->label('文案')->required()->max(190);
        $form->select('color')->label('徽章颜色')->options(DictItem::colorOptions());
        $form->number('sort')->label('排序')->default(0);
        $form->switch('enabled')->label('是否启用')->default(true);
    }

    public static function show(Show $show): void
    {
        $show->fields(['id', 'type_code', 'value', 'label', 'color', 'sort', 'enabled', 'created_at', 'updated_at']);
    }

    /**
     * 字典分类下拉（含代码声明的那些）。
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        $out = [];

        foreach ((new Dictionary)->all() as $code => $info) {
            $source = $info['source'] === 'code' ? '代码声明' : '后台维护';
            $out[$code] = $info['name'].'（'.$source.'）';
        }

        return $out;
    }
}
