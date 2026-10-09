<?php

declare(strict_types=1);

namespace Aimanong\Extend;

use Illuminate\Support\Facades\Log;

/**
 * 扩展管理器。
 *
 * 职责：
 *   1. 注册与启动扩展
 *   2. **依赖检查** —— 缺失依赖时明确拒绝，而不是运行时崩溃
 *   3. **失败隔离** —— 单个扩展出错不影响其它扩展与框架本身
 */
class ExtensionManager
{
    /**
     * @var array<string, Extension>
     */
    protected array $extensions = [];

    /**
     * @var array<string, string> 扩展名 => 失败原因
     */
    protected array $failed = [];

    /**
     * @var array<int, string>
     */
    protected array $booted = [];

    /**
     * 注册一个扩展实例。
     */
    public function add(Extension $extension): static
    {
        $this->extensions[$extension->name()] = $extension;

        return $this;
    }

    /**
     * 批量注册（按类名实例化）。
     *
     * 入参放宽为 string：配置里可能填错类名，
     * 此时应记录失败而非类型错误。
     *
     * @param  array<int, string>  $classes
     */
    public function addMany(array $classes): static
    {
        foreach ($classes as $class) {
            if (! class_exists($class)) {
                $this->failed[$class] = '类不存在';

                continue;
            }

            try {
                $instance = new $class;

                if (! $instance instanceof Extension) {
                    $this->failed[$class] = '未继承 Extension';

                    continue;
                }

                $this->add($instance);
            } catch (\Throwable $e) {
                $this->failed[$class] = '实例化失败: '.$e->getMessage();
            }
        }

        return $this;
    }

    /**
     * 检查依赖是否满足。
     *
     * @return array<int, string> 缺失的依赖名
     */
    public function missingDependencies(Extension $extension): array
    {
        $missing = [];

        foreach ($extension->dependencies() as $dep) {
            if (! isset($this->extensions[$dep])) {
                $missing[] = $dep;
            }
        }

        return $missing;
    }

    /**
     * 注册全部扩展。
     *
     * 依赖不满足的会被跳过并记录原因，而不是抛异常中断启动。
     */
    public function register(): void
    {
        foreach ($this->extensions as $name => $extension) {
            $missing = $this->missingDependencies($extension);

            if ($missing !== []) {
                $this->failed[$name] = '缺少依赖: '.implode(', ', $missing);

                continue;
            }

            try {
                $extension->register();
            } catch (\Throwable $e) {
                $this->failed[$name] = 'register() 失败: '.$e->getMessage();

                // 失败隔离：记录但不中断其它扩展
                $this->logFailure($name, 'register', $e);
            }
        }
    }

    /**
     * 启动全部扩展。
     */
    public function boot(): void
    {
        foreach ($this->extensions as $name => $extension) {
            // register 阶段失败的扩展不再 boot
            if (isset($this->failed[$name])) {
                continue;
            }

            try {
                $extension->boot();
                $this->booted[] = $name;
            } catch (\Throwable $e) {
                $this->failed[$name] = 'boot() 失败: '.$e->getMessage();

                $this->logFailure($name, 'boot', $e);
            }
        }
    }

    /**
     * 记录失败。
     *
     * 用 try/catch 包住：无 Laravel 容器时（如单元测试）
     * 日志facade 不可用，不能让管理器自身崩溃 —— 那会破坏失败隔离。
     */
    protected function logFailure(string $name, string $phase, \Throwable $e): void
    {
        try {
            Log::warning(
                "[Aimanong] 扩展 {$name} {$phase}() 失败",
                ['exception' => $e]
            );
        } catch (\Throwable) {
            // 日志不可用时静默 —— 失败原因已记录在 $this->failed
        }
    }

    /**
     * @return array<string, Extension>
     */
    public function all(): array
    {
        return $this->extensions;
    }

    public function find(string $name): ?Extension
    {
        return $this->extensions[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->extensions[$name]);
    }

    /**
     * @return array<int, string>
     */
    public function bootedNames(): array
    {
        return $this->booted;
    }

    /**
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failed;
    }

    public function count(): int
    {
        return count($this->extensions);
    }

    /**
     * 扩展清单（供自省接口与后台展示）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->extensions as $name => $ext) {
            $info = $ext->toArray();
            $info['booted'] = in_array($name, $this->booted, true);
            $info['error'] = $this->failed[$name] ?? null;

            $out[] = $info;
        }

        return $out;
    }
}
