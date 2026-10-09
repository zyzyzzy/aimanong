<?php

declare(strict_types=1);

namespace Aimanong\Menu;

/**
 * 菜单项。
 *
 * 由 Resource 的 `menu()` 声明生成。
 */
class MenuItem
{
    public function __construct(
        public readonly string $uri,
        public readonly string $label,
        public readonly string $group = '',
        public readonly string $icon = '',
        public readonly int $sort = 100,
        public readonly bool $visible = true,
    ) {}

    /**
     * 从 Resource 的 menu() 声明构造。
     *
     * @param  class-string  $class
     * @param  array<string, mixed>  $decl
     */
    public static function fromDeclaration(string $class, array $decl): self
    {
        /** @var string $uri */
        $uri = $class::uri();

        /** @var string $label */
        $label = $class::label();

        return new self(
            uri: $uri,
            label: is_string($decl['label'] ?? null) ? (string) $decl['label'] : $label,
            group: is_string($decl['group'] ?? null) ? (string) $decl['group'] : '',
            icon: is_string($decl['icon'] ?? null) ? (string) $decl['icon'] : '',
            sort: is_numeric($decl['sort'] ?? null) ? (int) $decl['sort'] : 100,
            visible: (bool) ($decl['visible'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'label' => $this->label,
            'group' => $this->group,
            'icon' => $this->icon,
            'sort' => $this->sort,
        ];
    }
}
