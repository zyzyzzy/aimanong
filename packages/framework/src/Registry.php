<?php

declare(strict_types=1);

namespace Aimanong;

/**
 * Resource 注册表。
 *
 * 所有 Resource 在此登记，供路由注册、AI 自省（/__ai/*）与菜单生成使用。
 * 这是 M1 Schema 编译层与 M3 AI 能力层的数据源。
 */
class Registry
{
    /**
     * @var array<string, class-string<Contracts\Resource>>
     */
    protected array $resources = [];

    /**
     * 登记一个 Resource。
     *
     * @param  class-string<Contracts\Resource>  $resource
     */
    public function register(string $resource): static
    {
        if (! class_exists($resource)) {
            throw new \InvalidArgumentException("Resource 类不存在: {$resource}");
        }

        $uri = $resource::uri();
        $this->resources[$uri] = $resource;

        return $this;
    }

    /**
     * @return array<string, class-string<Contracts\Resource>>
     */
    public function all(): array
    {
        return $this->resources;
    }

    /**
     * @return class-string<Contracts\Resource>|null
     */
    public function find(string $uri): ?string
    {
        return $this->resources[$uri] ?? null;
    }

    public function count(): int
    {
        return count($this->resources);
    }
}
