<?php

declare(strict_types=1);

namespace App\Aimanong;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Show\Show;
use App\Models\EquipmentRepair;

/**
 * 设备报修单。
 *
 * 需求映射：
 *   1. 列表：单号 / 设备 / 报修人 / 状态 / 紧急程度 / 报修时间 / 处理人
 *      设备名称与报修人可搜索，报修时间可排序，导出 CSV 给行政部做月度统计
 *   2. 状态与紧急程度：走数据字典 → 中文文案 + 徽章语义色，
 *      字典条目在「数据字典」界面由运营自己维护（is_locked=0），改文案不用找开发
 *   3. 表单：单号唯一（DB 唯一索引，框架自动识别）、设备/报修人必填、
 *      故障描述多行、现场照片多图、附件多文件、报修时间、处理人与处理时间
 *   4. 处理留痕：框架基座 admin-operation-logs 自动记录写操作，本 Resource 不写一行代码
 *   5. 权限：RBAC 已开启，权限节点自动生成（equipment-repairs.index/show/create/update/destroy/export）
 */
class EquipmentRepairResource extends Resource
{
    public static function model(): string
    {
        return EquipmentRepair::class;
    }

    public static function label(): string
    {
        return '设备报修';
    }

    public static function uri(): string
    {
        return 'equipment-repairs';
    }

    public static function menu(): array
    {
        return ['group' => '行政', 'icon' => '🛠️', 'sort' => 8];
    }

    public static function grid(Grid $grid): void
    {
        $grid->perPage(20);

        // 列表要导出给行政部做月度统计：导出复用当前搜索/筛选/排序条件
        $grid->export();
        // 照片路径是内部存储信息，对月度统计没有意义，导出时排除
        $grid->exportExcept(['photos']);

        $grid->column('id', 'ID')->sortable();
        $grid->column('repair_no', '报修单号')->searchable();
        $grid->column('equipment_name', '设备名称')->searchable();
        $grid->column('reporter', '报修人')->searchable();
        // 状态与紧急程度：文案 + 配色都来自数据字典，列表/表单/筛选共用一份
        $grid->column('status', '状态')->dict('equipment_repair_status')->filter();
        $grid->column('urgency', '紧急程度')->dict('equipment_repair_urgency')->filter();
        $grid->column('reported_at', '报修时间')->dateTime('Y-m-d H:i')->sortable();
        $grid->column('handler', '处理人')->filter();
        $grid->column('photos', '现场照片')->image(36);
    }

    public static function form(Form $form): void
    {
        $form->text('repair_no')->label('报修单号')
            ->required()
            ->max(60)
            ->help('全表唯一。唯一性由数据库唯一索引保证，重复提交会被拒绝。');

        $form->text('equipment_name')->label('设备名称')->required()->max(190);
        $form->text('reporter')->label('报修人')->required()->max(100);

        $form->textarea('description')->label('故障描述')->rows(5);

        $form->images('photos')->label('现场照片')
            ->maxSize(5120)
            ->help('可多选，单张不超过 5 MB。');

        $form->files('attachments')->label('相关附件')
            ->accept('pdf,docx,xlsx,jpg,png')
            ->maxSize(10240)
            ->help('可多选，如维修报价单，单个不超过 10 MB。');

        $form->datetime('reported_at')->label('报修时间');

        $form->select('status')->label('状态')
            ->dict('equipment_repair_status')
            ->default('pending')
            ->required();

        $form->select('urgency')->label('紧急程度')
            ->dict('equipment_repair_urgency')
            ->default('normal')
            ->required();

        $form->divider('处理信息');

        $form->text('handler')->label('处理人')->max(100)
            ->help('处理完成后填写。');

        $form->datetime('handled_at')->label('处理时间')
            ->help('处理完成后填写。');
    }

    public static function show(Show $show): void
    {
        $show->fields([
            'id', 'repair_no', 'equipment_name', 'reporter', 'description',
            'status', 'urgency', 'photos', 'attachments',
            'reported_at', 'handler', 'handled_at', 'created_at', 'updated_at',
        ]);
    }
}
