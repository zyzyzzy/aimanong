<?php

declare(strict_types=1);

namespace Aimanong\Schema\Emitters;

use Aimanong\Schema\Ast\FieldNode;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Support\Drivers;
use Aimanong\Support\FieldType;

/**
 * 产出前端运行时 JSON Schema。
 *
 * 前端 Vue 组件直接消费此产物渲染页面，
 * 因此前端无需硬编码任何字段信息。
 */
class JsonSchemaEmitter
{
    /**
     * @return array<string, mixed>
     */
    public function emit(ResourceNode $node): array
    {
        return [
            '$schema' => 'https://aimanong.com/schema/v1.json',
            'uri' => $node->uri,
            'label' => $node->label,
            'model' => $node->model,
            // 只读 Resource：前端据此隐藏新增/编辑/删除入口
            'readonly' => $node->meta['readonly'] ?? false,
            /*
             * 上传配置随 Schema 下发。
             *
             * 前端因此不需要硬编码 /admin/uploads 这类路径 ——
             * 换磁盘、换 CDN、换路由前缀都不用改前端。
             */
            'upload' => $this->upload(),
            'grid' => $this->grid($node),
            'form' => $this->form($node),
            'show' => $this->show($node),
            'generatedAt' => null, // 快照测试时保持确定性
        ];
    }

    /**
     * 上传能力（前端表单组件用）。
     *
     * @return array<string, mixed>
     */
    protected function upload(): array
    {
        return Drivers::upload()->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    protected function grid(ResourceNode $node): array
    {
        return [
            'perPage' => $node->meta['perPage'] ?? 20,
            /*
             * 列表展示形态。前端据此选渲染分支（表格 / 列表 / 卡片）。
             *
             * 与 batchActions 同理：这一项在 Compiler 与 Emitter 两处
             * 任一漏掉，`$grid->mode('cards')` 就成空操作。
             */
            'mode' => $node->meta['mode'] ?? 'table',
            'exportable' => $node->meta['exportable'] ?? false,
            /*
             * 批量操作按钮。前端据此决定是否渲染选择列与批量按钮。
             *
             * ⚠️ 与 Compiler 同样曾经漏掉这一项 —— 于是
             * `$grid->batchActions([...])` 是一条**完全断掉的链路**：
             * 声明了、文档写了、能力面也列了，但 schema 里没有、
             * 界面没有、后端也没有。
             */
            'batchActions' => $node->meta['batchActions'] ?? [],
            /*
             * 自定义批量动作的声明（动作名 + 标签 + 处理器）。
             *
             * ⚠️ 必须一起下发：前端要靠它拿**标签**（否则按钮上显示的是
             * 英文动作名 `ship` 而不是「标记为已发货」）。
             * 这正是 batchActions 当初漏掉时踩的同一类坑 ——
             * 声明层有、schema 没有，界面就无从知道。
             */
            'customBatchActions' => $node->meta['customBatchActions'] ?? [],
            // tree 为 null 表示非树形 Resource —— 前端据此切换渲染模式
            'tree' => $node->meta['tree'] ?? null,
            'columns' => array_map(
                fn ($c): array => $c->toArray(),
                $node->columns
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function form(ResourceNode $node): array
    {
        $fields = array_map(
            fn (FieldNode $f): array => $this->field($f),
            $node->fields
        );

        $steps = [];

        // 分步表单：把字段按步骤切分（字段定义已在编译期绑定到步骤）
        if (($node->meta['stepped'] ?? false) && ! empty($node->meta['steps'])) {
            $byName = [];
            foreach ($fields as $f) {
                $byName[$f['name']] = $f;
            }

            foreach ($node->meta['steps'] as $step) {
                $stepFields = [];

                foreach ($step['fields'] ?? [] as $sf) {
                    $name = is_array($sf) ? ($sf['name'] ?? null) : null;

                    if ($name !== null && isset($byName[$name])) {
                        $stepFields[] = $byName[$name];
                    }
                }

                $steps[] = ['title' => $step['title'] ?? '', 'fields' => $stepFields];
            }
        }

        return [
            'fields' => $fields,
            'rules' => $node->meta['rules'] ?? [],
            'stepped' => $node->meta['stepped'] ?? false,
            'steps' => $steps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function show(ResourceNode $node): array
    {
        return [
            'fields' => array_map(
                fn (FieldNode $f): array => $this->field($f),
                $node->detailFields
            ),
            /*
             * 详情页的**结构**（分组 / 标签页）。
             *
             * kind = flat 表示没声明 layout()，前端按平铺渲染 ——
             * 「没声明」与「声明了空结构」是两件事，不能混。
             */
            'layout' => $node->meta['layout'] ?? ['kind' => 'flat', 'groups' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function field(FieldNode $f): array
    {
        return [
            'name' => $f->name,
            'type' => $f->type,
            'label' => $f->label,
            'jsonType' => FieldType::toJsonType($f->type),
            'required' => $f->required,
            'readonly' => $f->readonly,
            'hidden' => $f->hidden,
            'default' => $f->default,
            'rules' => $f->rules,
            'props' => $f->props,
        ];
    }
}
