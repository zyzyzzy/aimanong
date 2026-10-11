<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Ai\Capabilities;
use Aimanong\Support\FieldType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 文档检索（本地知识，无需联网）。
 */
class SearchDocs extends Tool
{
    protected string $description = <<<'MARKDOWN'
        在框架内置文档中检索用法（字段类型、列选项、表单选项、校验规则、铁律）。

        本地检索，无需联网。不确定某个 API 怎么用就调它。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $query = strtolower((string) $request->string('query'));

        $sections = [
            '铁律' => [
                '方法签名唯一，不存在重载，不要猜第二种写法',
                '不嵌套闭包，所有配置走链式调用',
                '字段必须是数据库真实字段或已定义访问器',
                '状态类字段用 PHP Enum + ->options(Enum::cases())',
                '生成后必须调用 validate_declaration',
                '不要写前端代码 —— 前端由 Schema 自动渲染',
            ],
            '字段类型' => array_map(
                fn (string $t): string => sprintf('%s → JSON 类型 %s', $t, FieldType::toJsonType($t)),
                FieldType::all()
            ),
            /*
             * 列选项与表单选项一律从 Capabilities 取数，不在此硬编码。
             * 教训：M4 新增 bool()/money() 等展示器时只改了 Capabilities，
             * 此处仍是旧的 8 项 —— 导致 search-docs 与 capabilities.json
             * 数据分叉，AI 若只信前者就找不到新能力。
             */
            '列选项' => $this->columnOptions(),
            '表单选项' => $this->formOptions(),
            '树形结构' => $this->treeOptions(),
            '分步表单' => [
                '声明方式：$form->step(\'步骤标题\'); 后续字段自动归属该步骤',
                '例：$form->step(\'基本信息\'); $form->text(\'name\'); $form->step(\'联系方式\'); $form->email(\'email\');',
                '字段归属由声明顺序决定 —— 扁平、无嵌套闭包（框架铁律）',
            ],
            '只读资源' => $this->foundationOptions('readonly'),
            '基座功能' => $this->foundationOptions('foundation'),
            '扩展与多应用' => $this->extensionAndApplicationOptions(),
            '需求核对' => $this->requirementKeys(),
            '工作流' => [
                '1. list_resources 了解项目',
                '2. describe_resource 看字段',
                '3. scaffold_resource 从表生成（推荐）',
                '4. validate_declaration 校验',
                '5. run_verify 整体自检',
            ],
        ];

        if ($query === '') {
            $out = ['# Aimanong 文档', ''];
            foreach ($sections as $title => $items) {
                $out[] = "## {$title}";
                foreach ($items as $item) {
                    $out[] = "- {$item}";
                }
                $out[] = '';
            }

            return Response::text(implode("\n", $out));
        }

        $out = ["# 检索: {$query}", ''];
        $hit = 0;

        foreach ($sections as $title => $items) {
            $matched = array_values(array_filter(
                $items,
                fn (string $i): bool => str_contains(strtolower($i), $query)
                    || str_contains(strtolower($title), $query)
            ));

            if ($matched === []) {
                continue;
            }

            $out[] = "## {$title}";
            foreach ($matched as $m) {
                $out[] = "- {$m}";
                $hit++;
            }
            $out[] = '';
        }

        if ($hit === 0) {
            return Response::text(sprintf(
                "未找到与 '%s' 相关的内容。\n\n可检索的主题: %s",
                $query,
                implode(' / ', array_keys($sections))
            ));
        }

        return Response::text(implode("\n", $out));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('检索关键词，留空则返回全部文档'),
        ];
    }

    /**
     * 树形结构选项 —— 从 Capabilities 取数。
     *
     * @return array<int, string>
     */
    protected function treeOptions(): array
    {
        $out = [];

        foreach ((new Capabilities)->treeOptions() as $name => $desc) {
            $out[] = "tree()->{$name}() {$desc}";
        }

        return $out;
    }

    /**
     * validate_declaration 支持的 requirements 键 —— 从 Capabilities 取数。
     *
     * @return array<int, string>
     */
    protected function requirementKeys(): array
    {
        $out = ['传给 validate_declaration 的 requirements 参数，支持以下键：'];

        foreach ((new Capabilities)->requirementKeys() as $k => $v) {
            $out[] = "{$k}: {$v}";
        }

        $out[] = '注意：不认识的键会被报错，不会静默忽略';

        return $out;
    }

    /**
     * 扩展与多应用选项 —— 从 Capabilities 取数。
     *
     * @return array<int, string>
     */
    protected function extensionAndApplicationOptions(): array
    {
        $caps = new Capabilities;

        $out = ['【扩展（插件）】'];
        foreach ($caps->extensionCapabilities() as $k => $v) {
            $out[] = "{$k}: {$v}";
        }

        $out[] = '【多应用】';
        foreach ($caps->applicationCapabilities() as $k => $v) {
            $out[] = "{$k}: {$v}";
        }

        return $out;
    }

    /**
     * 列选项 —— 从 Capabilities 取数，保证与 /__ai/capabilities.json 同源。
     *
     * @return array<int, string>
     */
    protected function columnOptions(): array
    {
        $out = [];

        foreach ((new Capabilities)->columnOptions() as $name => $desc) {
            $out[] = "{$name}() {$desc}";
        }

        return $out;
    }

    /**
     * 基座能力（P0）—— 从 Capabilities 取数。
     *
     * `readonly` 单列一节，因为它是**声明写法**，
     * AI 需要能一眼找到「怎么写」；其余是「已经存在、不要重复造」的告知。
     *
     * @return array<int, string>
     */
    protected function foundationOptions(string $mode): array
    {
        $foundation = (new Capabilities)->foundationCapabilities();
        $out = [];

        foreach ($foundation as $key => $info) {
            if ($mode === 'readonly' && $key !== 'readonly') {
                continue;
            }

            if ($mode === 'foundation' && $key === 'readonly') {
                continue;
            }

            $line = "{$key}() {$info['summary']}";

            if (isset($info['enabled'])) {
                $line .= $info['enabled'] ? '（已启用）' : '（已关闭）';
            }

            $out[] = $line;

            foreach (['example', 'detail', 'auto', 'excludes', 'when', 'use_when', 'config', 'uri'] as $field) {
                if (isset($info[$field]) && is_string($info[$field])) {
                    $out[] = "  - {$field}: {$info[$field]}";
                }
            }
        }

        return $out;
    }

    /**
     * 表单选项 —— 从 Capabilities 取数。
     *
     * @return array<int, string>
     */
    protected function formOptions(): array
    {
        $out = [];

        foreach ((new Capabilities)->formOptions() as $name => $desc) {
            $out[] = "{$name}() {$desc}";
        }

        return $out;
    }
}
