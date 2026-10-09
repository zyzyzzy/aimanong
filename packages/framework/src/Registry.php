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
     * 登记失败记录（类不存在等）。
     *
     * @var array<int, string>
     */
    protected array $failures = [];

    /**
     * 登记一个 Resource。
     *
     * 容错设计：类不存在时**记录失败而非抛异常**。
     *
     * 原因：用户的 ServiceProvider 里可能残留已删除的 Resource 注册，
     * 抛异常会导致整个应用（含所有 artisan 命令）崩溃 ——
     * 连 `ai:verify` 都用不了，用户无法自救。
     *
     * 失败信息可通过 failures() 查看，`ai:verify` 会报告出来。
     *
     * @param  class-string<Contracts\Resource>|string  $resource
     */
    public function register(string $resource): static
    {
        if (! class_exists($resource)) {
            $this->failures[] = $resource;

            return $this;
        }

        if (! is_subclass_of($resource, Contracts\Resource::class)) {
            $this->failures[] = $resource.'（未继承 Aimanong\\Resource）';

            return $this;
        }

        try {
            $uri = $resource::uri();
        } catch (\Throwable $e) {
            $this->failures[] = $resource.'（uri() 抛错: '.$e->getMessage().'）';

            return $this;
        }

        $this->resources[$uri] = $resource;

        return $this;
    }

    /**
     * 登记失败的 Resource（类不存在 / 未继承 / uri() 抛错）。
     *
     * @return array<int, string>
     */
    public function failures(): array
    {
        return $this->failures;
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
