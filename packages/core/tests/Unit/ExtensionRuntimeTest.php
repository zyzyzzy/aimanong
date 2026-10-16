<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Extend\Extension;
use Aimanong\Extend\ExtensionPlatform;
use Aimanong\Extend\ExtensionRuntime;
use PHPUnit\Framework\TestCase;

/**
 * 扩展平台的静态注入点。
 *
 * ## 为什么未注册时要**抛异常**而不是静默退化
 *
 * `views()` / `translations()` / `routes()` 一旦不生效，
 * 扩展的路由和视图会**静默消失** —— 症状是「装了扩展但页面 404」，
 * 而没有任何线索指向「平台没注册」。宁可启动就报一个说得清的错。
 *
 * 这条断言就是钉住这个决定：有人想把它改成「静默返回」时，测试会红。
 */
class ExtensionRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        ExtensionRuntime::reset();
        parent::tearDown();
    }

    public function test_starts_unregistered(): void
    {
        $this->assertFalse(ExtensionRuntime::registered());
    }

    public function test_current_throws_when_unregistered(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/扩展平台未注册/');

        ExtensionRuntime::current();
    }

    /**
     * 异常信息必须**告诉人怎么修**（宪法⑥ 错误可自愈）——
     * 只说「未注册」等于让人去翻源码。
     */
    public function test_exception_says_how_to_fix(): void
    {
        try {
            ExtensionRuntime::current();
            $this->fail('应该抛异常');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ExtensionRuntime::use', $e->getMessage());
            $this->assertStringContainsString('AimanongServiceProvider', $e->getMessage());
            $this->assertStringContainsString('ThinkService', $e->getMessage());
        }
    }

    public function test_registered_platform_is_returned(): void
    {
        $platform = new class implements ExtensionPlatform
        {
            public function routes(Extension $extension, \Closure $callback, string $prefix): void {}

            public function views(string $namespace, string $path): void {}

            public function translations(string $namespace, string $path): void {}

            public function logFailure(string $name, string $phase, \Throwable $e): void {}
        };

        ExtensionRuntime::use($platform);

        $this->assertTrue(ExtensionRuntime::registered());
        $this->assertSame($platform, ExtensionRuntime::current());
    }

    public function test_reset_unregisters(): void
    {
        ExtensionRuntime::use(new class implements ExtensionPlatform
        {
            public function routes(Extension $extension, \Closure $callback, string $prefix): void {}

            public function views(string $namespace, string $path): void {}

            public function translations(string $namespace, string $path): void {}

            public function logFailure(string $name, string $phase, \Throwable $e): void {}
        });

        ExtensionRuntime::reset();

        $this->assertFalse(ExtensionRuntime::registered());
    }
}
