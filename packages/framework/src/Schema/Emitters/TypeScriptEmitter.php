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
    public function emit(ResourceNode $node): string
    {
        $lines = [];

        $lines[] = '// 自动生成，请勿手工编辑。';
        $lines[] = '// 来源: Aimanong Schema 编译层';
        $lines[] = '// 变更 Resource 声明后请重新生成。';
        $lines[] = '';
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

        return sprintf(
            "export interface %s {\n  uri: '%s';\n  label: '%s';\n  grid: AimanongGridSchema;\n  form: AimanongFormSchema;\n}",
            $name,
            $node->uri,
            $node->label
        );
    }

    protected function constExport(ResourceNode $node): string
    {
        $name = $this->pascal($node->uri).'Schema';

        $json = json_encode(
            (new JsonSchemaEmitter())->emit($node),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        return sprintf(
            "export const %s: %s = %s as %s;",
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
