<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Application\ApplicationContext;
use PHPUnit\Framework\TestCase;

/**
 * 应用上下文测试 + 防回归检查。
 *
 * 背景：多应用 switch() 会改写全局 config，任何直接读 config 的代码
 * 都可能拿到别的应用的值。这个坑反复踩了 4 次（url / guard / Session / Asset），
 * 因此这里用**静态扫描**防止再次引入。
 */
class ApplicationContextTest extends TestCase
{
    /**
     * 禁止在 ApplicationContext 之外直接读可被污染的配置。
     */
    public function test_no_direct_reads_of_pollutable_config(): void
    {
        $src = dirname(__DIR__, 2).'/src';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            // 唯一允许直接读的地方
            if (str_ends_with($path, 'Application/ApplicationContext.php')
                || str_ends_with($path, 'Application/ApplicationManager.php')) {
                continue;
            }

            $content = (string) file_get_contents($path);

            // 去掉所有注释后再检查，避免把文档说明误判为真实读取
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $content);  // /** */ 与 /* */
            $code = (string) preg_replace('#//.*#', '', $code);            // 行注释

            foreach (['aimanong.route.prefix', 'aimanong.auth.guard'] as $key) {
                if (str_contains($code, "config('{$key}')") || str_contains($code, "config(\"{$key}\")")) {
                    // AimanongServiceProvider 的单后台分支是允许的
                    if (str_ends_with($path, 'AimanongServiceProvider.php')) {
                        continue;
                    }

                    $violations[] = str_replace($src.'/', '', $path)." → {$key}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "以下文件直接读取了可被多应用污染的配置，请改用 Aimanong::context():\n  ".implode("\n  ", $violations)
        );
    }

    public function test_context_is_registered_in_container(): void
    {
        $ref = new \ReflectionClass(ApplicationContext::class);

        foreach (['prefix', 'guard', 'app', 'config', 'flush'] as $m) {
            $this->assertTrue($ref->hasMethod($m), "ApplicationContext 缺少方法 {$m}");
        }
    }

    public function test_context_falls_back_without_container(): void
    {
        /*
         * 无容器（极早期引导 / 单元测试）时不应抛异常 ——
         * 否则框架连启动都做不到。应回退到安全默认值。
         */
        $ctx = new ApplicationContext;

        $this->assertSame('admin', $ctx->prefix());
        $this->assertSame('admin', $ctx->guard());
        $this->assertSame('admin', $ctx->app());
        $this->assertNull($ctx->domain());
    }
}
