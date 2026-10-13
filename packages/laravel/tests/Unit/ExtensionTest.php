<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Extend\Extension;
use Aimanong\Extend\ExtensionManager;
use PHPUnit\Framework\TestCase;

/**
 * 扩展系统测试。
 *
 * 重点：**失败隔离** —— 单个扩展出错不应拖垮框架或其它扩展。
 * 这对插件生态至关重要：第三方扩展质量参差不齐。
 */
class ExtensionTest extends TestCase
{
    protected function manager(): ExtensionManager
    {
        return new ExtensionManager;
    }

    public function test_registers_and_boots_normal_extension(): void
    {
        $ext = new class extends Extension
        {
            public bool $registered = false;

            public bool $booted = false;

            public function name(): string
            {
                return 'normal';
            }

            public function register(): void
            {
                $this->registered = true;
            }

            public function boot(): void
            {
                $this->booted = true;
            }
        };

        $m = $this->manager()->add($ext);
        $m->register();
        $m->boot();

        $this->assertTrue($ext->registered);
        $this->assertTrue($ext->booted);
        $this->assertSame(['normal'], $m->bootedNames());
    }

    public function test_skips_extension_with_missing_dependency(): void
    {
        $ext = new class extends Extension
        {
            public bool $booted = false;

            public function name(): string
            {
                return 'needs-dep';
            }

            public function dependencies(): array
            {
                return ['not-installed'];
            }

            public function boot(): void
            {
                $this->booted = true;
            }
        };

        $m = $this->manager()->add($ext);
        $m->register();
        $m->boot();

        $this->assertFalse($ext->booted, '依赖缺失的扩展不应启动');
        $this->assertStringContainsString('缺少依赖', $m->failures()['needs-dep']);
    }

    /**
     * 核心：一个扩展炸了，其它扩展必须照常工作。
     */
    public function test_failure_is_isolated(): void
    {
        $broken = new class extends Extension
        {
            public function name(): string
            {
                return 'broken';
            }

            public function register(): void
            {
                throw new \RuntimeException('模拟注册失败');
            }
        };

        $healthy = new class extends Extension
        {
            public bool $booted = false;

            public function name(): string
            {
                return 'healthy';
            }

            public function boot(): void
            {
                $this->booted = true;
            }
        };

        $m = $this->manager()->add($broken)->add($healthy);
        $m->register();
        $m->boot();

        $this->assertTrue($healthy->booted, '正常扩展不应被损坏扩展拖垮');
        $this->assertSame(['healthy'], $m->bootedNames());
        $this->assertArrayHasKey('broken', $m->failures());
    }

    public function test_add_many_reports_invalid_class(): void
    {
        $m = $this->manager()->addMany(['Not\\A\\Real\\Class']);

        $this->assertSame(0, $m->count());
        $this->assertNotEmpty($m->failures());
    }

    public function test_to_array_exposes_status(): void
    {
        $ext = new class extends Extension
        {
            public function name(): string
            {
                return 'demo';
            }

            public function title(): string
            {
                return '演示扩展';
            }

            public function version(): string
            {
                return '2.0.0';
            }
        };

        $m = $this->manager()->add($ext);
        $m->register();
        $m->boot();

        $info = $m->toArray()[0];

        $this->assertSame('demo', $info['name']);
        $this->assertSame('演示扩展', $info['title']);
        $this->assertSame('2.0.0', $info['version']);
        $this->assertTrue($info['booted']);
        $this->assertNull($info['error']);
    }
}
