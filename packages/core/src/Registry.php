<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Support\Drivers;

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
     * 各 Resource 属于哪些后台。uri => 应用名数组。**空数组 = 所有后台**。
     *
     * @var array<string, array<int, string>>
     */
    protected array $applications = [];

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
    /**
     * @param  class-string<Contracts\Resource>|string  $resource
     * @param  array<int, string>|null  $applications  归属后台；null = 取 Resource::applications()
     */
    public function register(string $resource, ?array $applications = null): static
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

        // 注册时显式指定优先，否则用 Resource 自己的声明
        if ($applications !== null) {
            $this->applications[$uri] = array_values(array_filter(
                $applications,
                // 参数已是 array<int, string>，只需去掉空串与重复项
                static fn (string $a): bool => $a !== '',
            ));
        } else {
            $this->applications[$uri] = method_exists($resource, 'applications')
                ? self::normalizeApplications($resource::applications())
                : [];
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    protected static function normalizeApplications(mixed $declared): array
    {
        if (! is_array($declared)) {
            return [];
        }

        $out = [];

        foreach ($declared as $app) {
            if (is_string($app) && $app !== '') {
                $out[] = $app;
            }
        }

        return $out;
    }

    /**
     * 某 Resource 属于哪些后台。**空数组 = 属于所有后台。**
     *
     * @return array<int, string>
     */
    public function applicationsFor(string $uri): array
    {
        $key = $this->normalizeUri($uri);

        if (isset($this->applications[$key])) {
            return $this->applications[$key];
        }

        $class = $this->find($uri);

        if ($class !== null && isset($this->applications[$this->normalizeUri($class::uri())])) {
            return $this->applications[$this->normalizeUri($class::uri())];
        }

        return [];
    }

    /**
     * 判断某 Resource 是否属于指定后台。
     *
     * 未声明归属（空数组）时属于**所有**后台。
     */
    public function belongsTo(string $uri, string $app): bool
    {
        $apps = $this->applicationsFor($uri);

        // 未声明归属 = 属于**所有**后台
        if ($apps === []) {
            return true;
        }

        /*
         * 解析 `@primary` 占位符 → `names()` 里的第一个应用。
         *
         * 内置的系统管理类 Resource 用它声明「只属于主后台」。
         * 写死应用名会让「主后台不叫 admin」的用户**静默失去**这些页面。
         */
        if (in_array(Contracts\ApplicationResolver::PRIMARY, $apps, true)) {
            $names = Drivers::application()->names();

            if (($names[0] ?? null) === $app) {
                return true;
            }

            $apps = array_values(array_diff($apps, [Contracts\ApplicationResolver::PRIMARY]));
        }

        return in_array($app, $apps, true);
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
     * 规范化 uri：下划线与连字符互通。
     *
     * 生成器按表名产出 `admin_roles`（下划线），
     * 而既有约定是 kebab（连字符）—— 两种写法都应能找到。
     */
    protected function normalizeUri(string $uri): string
    {
        return str_replace('_', '-', $uri);
    }

    /**
     * @return class-string<Contracts\Resource>|null
     */
    public function find(string $uri): ?string
    {
        $key = $this->normalizeUri($uri);

        if (isset($this->resources[$key])) {
            return $this->resources[$key];
        }

        // 再按原始写法找一次（兼容下划线注册的 Resource）
        foreach ($this->resources as $class) {
            // class-string<Resource> 必然有 uri()，无需 is_callable 判断
            if (class_exists($class)) {
                if ($this->normalizeUri($class::uri()) === $key) {
                    return $class;
                }
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->resources);
    }
}
