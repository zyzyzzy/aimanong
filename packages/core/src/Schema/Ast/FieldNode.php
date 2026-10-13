<?php

declare(strict_types=1);

namespace Aimanong\Schema\Ast;

/**
 * 字段 AST 节点。
 *
 * Aimanong 约定：所有配置都是显式的标量或数组，
 * 不含闭包、不含回调，保证可序列化为 JSON。
 */
class FieldNode
{
    /**
     * @param  array<string, mixed>  $props  字段特有属性（options / rows 等）
     * @param  array<int, string>  $rules  Laravel 验证规则
     * @param  array<string, mixed>  $meta  AI 提示词用的元信息
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly array $props = [],
        public readonly array $rules = [],
        public readonly mixed $default = null,
        public readonly bool $required = false,
        public readonly bool $readonly = false,
        public readonly bool $hidden = false,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'props' => $this->props,
            'rules' => $this->rules,
            'default' => $this->default,
            'required' => $this->required,
            'readonly' => $this->readonly,
            'hidden' => $this->hidden,
        ];
    }
}
