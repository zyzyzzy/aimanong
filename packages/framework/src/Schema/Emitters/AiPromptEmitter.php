<?php

declare(strict_types=1);

namespace Aimanong\Schema\Emitters;

use Aimanong\Schema\Ast\FieldNode;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Support\FieldType;

/**
 * 产出面向 AI 的提示词片段。
 *
 * 这是 Aimanong 独有的第四份产物：
 * 每个 Resource 自动生成一段给 AI 读的描述，
 * 直接喂给 LLM / MCP，无需手写文档。
 */
class AiPromptEmitter
{
    public function emit(ResourceNode $node): string
    {
        $lines = [];

        $lines[] = "## Resource: {$node->label} (uri: {$node->uri})";
        $lines[] = '';
        $lines[] = "- 绑定模型: `{$node->model}`";

        /*
         * 只读标志必须显式告知 AI。
         *
         * 否则 AI 会以为「这些数据管理员可以直接改」，生成
         * POST / PUT / DELETE 调用，而接口一律 403 ——
         * 对一个把「可自省」当卖点的框架，这属于自身声明失真。
         *
         * 回归：v1.6.0 引入只读资源后，本产物 0 处提到「只读」。
         */
        if (($node->meta['readonly'] ?? false) === true) {
            $lines[] = '- **只读资源**：只有列表 / 详情 / 导出；'
                .'`store` / `update` / `destroy` 一律 403，不要生成写操作调用';
        }

        if ($this->hasUploadFields($node)) {
            $lines[] = '- 含上传字段：库里存**相对路径**（如 `uploads/2026/10/x.png`），'
                .'不是完整 URL；展示时交给 Uploader 解析，不要自己拼域名';
        }

        $lines[] = '';

        $lines[] = '### 列表页可用列';
        if ($node->columns === []) {
            $lines[] = '（未定义列）';
        } else {
            foreach ($node->columns as $c) {
                $flags = [];
                if ($c->sortable) {
                    $flags[] = '可排序';
                }
                if ($c->searchable) {
                    $flags[] = '可搜索';
                }
                if ($c->filterable) {
                    $flags[] = '可筛选';
                }

                $lines[] = sprintf(
                    '- `%s`（%s）%s',
                    $c->name,
                    $c->label,
                    $flags !== [] ? '['.implode(' / ', $flags).']' : ''
                );
            }
        }

        $lines[] = '';
        $lines[] = '### 表单字段';
        if ($node->fields === []) {
            $lines[] = '（未定义字段）';
        } else {
            foreach ($node->fields as $f) {
                $lines[] = $this->describeField($f);
            }
        }

        $lines[] = '';
        $lines[] = '### 校验规则（JSON）';
        $rules = $node->meta['rules'] ?? [];
        $json = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $lines[] = '```json';
        $lines[] = $json !== false ? $json : '{}';
        $lines[] = '```';

        return implode("\n", $lines)."\n";
    }

    /**
     * 是否含上传类字段（image / images / file / files）。
     */
    protected function hasUploadFields(ResourceNode $node): bool
    {
        foreach ($node->fields as $field) {
            if (in_array($field->type, ['image', 'images', 'file', 'files'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function describeField(FieldNode $f): string
    {
        $parts = [];

        if ($f->required) {
            $parts[] = '必填';
        }
        if ($f->readonly) {
            $parts[] = '只读';
        }
        if ($f->hidden) {
            $parts[] = '隐藏';
        }
        if ($f->default !== null) {
            $parts[] = '默认 '.json_encode($f->default, JSON_UNESCAPED_UNICODE);
        }

        $options = $f->props['options'] ?? null;
        if (is_array($options) && $options !== []) {
            $values = implode(' | ', array_column($options, 'value'));
            $parts[] = "可选值: {$values}";
        }

        return sprintf(
            '- `%s` 类型 `%s`（%s）%s',
            $f->name,
            $f->type,
            $f->label,
            $parts !== [] ? '— '.implode('；', $parts) : ''
        );
    }

    /**
     * 生成全局字段类型清单（供 llms.txt 使用）。
     */
    public function emitFieldCatalog(): string
    {
        $lines = ['## 可用字段类型', ''];

        foreach (FieldType::all() as $type) {
            $lines[] = sprintf(
                '- `%s` → JSON 类型 `%s`',
                $type,
                FieldType::toJsonType($type)
            );
        }

        return implode("\n", $lines)."\n";
    }
}
