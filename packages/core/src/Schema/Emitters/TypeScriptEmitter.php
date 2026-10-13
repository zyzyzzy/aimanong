<?php

declare(strict_types=1);

namespace Aimanong\Schema\Emitters;

use Aimanong\Schema\Ast\FieldNode;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Support\FieldType;

/**
 * 产出 TypeScript 类型定义。
 *
 * 前端从此产物获得完整类型，实现端到端类型安全。
 */
class TypeScriptEmitter
{
    /**
     * 基类型定义 —— 必须置于 types.ts 顶部。
     *
     * 回归：此前 emit() 输出的接口引用了 `AimanongGridSchema` /
     * `AimanongFormSchema`，但**从来没有定义过它们** ——
     * 生成出来的 types.ts 直接编译不过（`Cannot find name`），
     * 而「端到端类型安全」正是这份产物的存在理由。
     *
     * 只在文件头输出一次，因此放在 SchemaCommand 拼装时调用。
     */
    public function header(): string
    {
        /*
         * ⚠️ 这里的类型必须与 emitter 的**真实产出**逐字对齐。
         *
         * 最初我按「合理的类型设计」写了这组基类型，结果用真实 tsc
         * 编译生成的 types.ts 时暴露四处不符：
         *   1. 字段里实际有 `jsonType`（我没写）
         *   2. 非树形资源 `grid.tree` 是 `null`（不是 `{}`）
         *   3. `props` 没有属性时是**空数组** `[]` 而不是对象 ——
         *      因为 PHP 的 `json_encode([])` 是 `[]`，不是 `{}`
         *   4. 无校验规则时 `form.rules` 也是空数组
         *
         * 教训：**基类型要照着产出写，不能照着想象写。**
         * 也因此本文件必须配一条「用 tsc 真编译」的验证（见 EmittersTest
         * 与 tests/e2e/tsc-check.sh），光断言字符串存在是抓不到这类问题的。
         */
        return <<<'TS'
        // 自动生成，请勿手工编辑。
        // 来源: Aimanong Schema 编译层
        // 变更 Resource 声明后请重新生成。

        /**
         * 列表页配置。
         *
         * 注：PHP 的 `json_encode([])` 产出 `[]` 而非 `{}`，
         * 所以「空」的联合类型里必须带 `never[]`。
         */
        export interface AimanongGridSchema {
          perPage: number;
          exportable: boolean;
          /** 非树形资源为 null */
          tree: Record<string, unknown> | null;
          columns: AimanongColumn[];
        }

        /** 表单页配置 */
        export interface AimanongFormSchema {
          fields: AimanongField[];
          /** 无校验规则时是空数组（只读资源就属于这种） */
          rules: Record<string, string[]> | never[];
          stepped: boolean;
          steps: Array<{ title: string; fields: AimanongField[] }>;
        }

        /** 字段定义 */
        export interface AimanongField {
          name: string;
          type: string;
          label: string;
          jsonType: string;
          required: boolean;
          readonly: boolean;
          hidden: boolean;
          default: unknown;
          rules: string[];
          /** 无属性时为空数组 */
          props: Record<string, unknown> | never[];
        }

        /** 列定义 */
        export interface AimanongColumn {
          name: string;
          label: string;
          sortable: boolean;
          searchable: boolean;
          filterable: boolean;
          formatter: string | null;
          enum: string | null;
          props: Record<string, unknown> | never[];
        }

        /**
         * 上传配置（随 Schema 下发）。
         *
         * `urlTemplate` 含字面量 `{path}` 占位符，前端用它把
         * 「存进数据库的相对路径」还原成可访问 URL。
         */
        export interface AimanongUploadConfig {
          urlTemplate: string;
          endpoint: string;
          disk: string;
          directory: string;
          imageMaxSize: number;
          fileMaxSize: number;
          imageExtensions: string[];
          fileExtensions: string[];
        }
        TS;
    }

    public function emit(ResourceNode $node): string
    {
        $lines = [];

        $lines[] = $this->modelInterface($node);
        $lines[] = '';
        $lines[] = $this->schemaInterface($node);
        $lines[] = '';
        $lines[] = $this->constExport($node);

        return implode("\n", $lines)."\n";
    }

    protected function modelInterface(ResourceNode $node): string
    {
        $name = $this->pascal($node->uri);

        $body = [];
        foreach ($node->fields as $field) {
            $body[] = sprintf(
                '  %s%s: %s;',
                $field->name,
                $field->required ? '' : '?',
                $this->tsType($field)
            );
        }

        if ($body === []) {
            $body[] = '  id: number;';
        }

        return sprintf(
            "export interface %s {\n%s\n}",
            $name,
            implode("\n", $body)
        );
    }

    protected function schemaInterface(ResourceNode $node): string
    {
        $name = $this->pascal($node->uri).'Schema';

        /*
         * 能力标志必须写进接口签名。
         *
         * `constExport()` 的 JSON 当然带有 readonly / upload，
         * 但**用接口类型的消费者看不到** —— TS 的 excess property check
         * 会让 `schema.readonly` 直接报错，逼使用者写 as any。
         *
         * 回归：v1.6.0 引入只读资源与上传后漏改这里，
         * 四份产物只有 schema.json 认识这两个标志（见 EmittersTest）。
         */
        return sprintf(
            "export interface %s {\n  uri: '%s';\n  label: '%s';\n  readonly: boolean;\n  upload: AimanongUploadConfig;\n  grid: AimanongGridSchema;\n  form: AimanongFormSchema;\n}",
            $name,
            $node->uri,
            $node->label
        );
    }

    protected function constExport(ResourceNode $node): string
    {
        $name = $this->pascal($node->uri).'Schema';

        $json = json_encode(
            (new JsonSchemaEmitter)->emit($node),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        return sprintf(
            'export const %s: %s = %s as %s;',
            lcfirst($name),
            $name,
            $json ?: '{}',
            $name
        );
    }

    protected function tsType(FieldNode $field): string
    {
        return match (FieldType::toPhpType($field->type)) {
            'int', 'float' => 'number',
            'bool' => 'boolean',
            'mixed' => 'unknown',
            default => 'string',
        };
    }

    protected function pascal(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
