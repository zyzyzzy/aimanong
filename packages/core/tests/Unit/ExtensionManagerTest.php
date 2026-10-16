<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Extend\Extension;
use Aimanong\Extend\ExtensionManager;
use Aimanong\Extend\ExtensionPlatform;
use Aimanong\Extend\ExtensionRuntime;
use PHPUnit\Framework\TestCase;

/**
 * 扩展管理器 —— 依赖解析与失败隔离。
 *
 * ## 这一段锁的是「一个扩展坏了不能拖垮后台」
 *
 * 扩展是**第三方代码**。它抛异常、名字填错、依赖没装，
 * 都不该让整个后台起不来 —— 那是把「一个插件的问题」升级成
 * 「所有人的事故」。所以管理器把所有失败吞进 `$failed` 并记录原因。
 *
 * 但「吞掉」不等于「不管」：失败原因必须能查到
 *（`failures()` + 日志），否则就成了静默失效。
 */
class ExtensionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 平台必须注册 —— 否则 Extension::routes() 会抛（刻意的）
        ExtensionRuntime::use(new class implements ExtensionPlatform
        {
            public array $logged = [];

            public function routes(Extension $extension, \Closure $callback, string $prefix): void
            {
                $callback();
            }

            public function views(string $namespace, string $path): void {}

            public function translations(string $namespace, string $path): void {}

            public function logFailure(string $name, string $phase, \Throwable $e): void
            {
                $this->logged[] = "{$name}:{$phase}";
            }
        });
    }

    protected function tearDown(): void
    {
        ExtensionRuntime::reset();
        parent::tearDown();
    }

    public function test_add_and_lookup(): void
    {
        $m = new ExtensionManager;
        $m->add(new CoreTestExtension('alpha'));

        $this->assertTrue($m->has('alpha'));
        $this->assertFalse($m->has('beta'));
        $this->assertSame(1, $m->count());
        $this->assertNull($m->find('beta'));
        $this->assertInstanceOf(CoreTestExtension::class, $m->find('alpha'));
        $this->assertSame(['alpha'], array_keys($m->all()));
    }

    public function test_register_and_boot_order(): void
    {
        $m = new ExtensionManager;
        $m->add(new CoreTestExtension('alpha'));

        $m->register();
        $this->assertSame(['alpha'], CoreTestExtension::$registerOrder);

        $m->boot();
        $this->assertSame(['alpha'], $m->bootedNames());
    }

    /**
     * 依赖不满足 → 跳过并记录，**不抛异常**。
     */
    public function test_missing_dependency_is_skipped_not_thrown(): void
    {
        $m = new ExtensionManager;
        $m->add(new CoreTestExtension('alpha', deps: ['not-installed']));

        $m->register();   // 不该抛
        $m->boot();

        $this->assertSame([], $m->bootedNames());
        $this->assertArrayHasKey('alpha', $m->failures());
        $this->assertStringContainsString('缺少依赖', $m->failures()['alpha']);
        $this->assertStringContainsString('not-installed', $m->failures()['alpha']);
    }

    /**
     * 依赖满足 → 正常注册。
     */
    public function test_met_dependency_passes(): void
    {
        $m = new ExtensionManager;
        $m->add(new CoreTestExtension('base'));
        $m->add(new CoreTestExtension('alpha', deps: ['base']));

        $m->register();
        $m->boot();

        $this->assertContains('alpha', $m->bootedNames());
        $this->assertContains('base', $m->bootedNames());
    }

    /**
     * register() 抛异常 → 记录 + 继续其它扩展（失败隔离）。
     */
    public function test_register_throwing_is_isolated(): void
    {
        $m = new ExtensionManager;
        $m->add(new CoreTestExtension('boom', throwOnRegister: true));
        $m->add(new CoreTestExtension('fine'));

        $m->register();   // 不该抛
        $m->boot();

        $this->assertContains('fine', $m->bootedNames(), '好的扩展不该被坏的拖累');
        $this->assertArrayNotHasKey('fine', $m->failures());
        $this->assertStringContainsString('register() 失败', $m->failures()['boom'] ?? '');
    }

    /**
     * register 阶段失败的扩展**不再 boot** ——
     * 半初始化状态下 boot 只会引发更难查的错。
     */
    public function test_failed_extension_is_not_booted(): void
    {
        $m = new ExtensionManager;
        $m->add(new CoreTestExtension('boom', throwOnRegister: true));

        $m->register();
        $m->boot();

        $this->assertSame([], $m->bootedNames());
        $this->assertFalse(CoreTestExtension::$booted['boom'] ?? false, 'boot() 不该被调用');
    }

    public function test_boot_throwing_is_isolated(): void
    {
        $m = new ExtensionManager;
        $m->add(new CoreTestExtension('boom', throwOnBoot: true));
        $m->add(new CoreTestExtension('fine'));

        $m->register();
        $m->boot();

        $this->assertContains('fine', $m->bootedNames());
        $this->assertNotContains('boom', $m->bootedNames());
        $this->assertStringContainsString('boot() 失败', $m->failures()['boom'] ?? '');
    }

    /**
     * addMany 要**容错**：配置里类名写错是常见错误，
     * 应记录失败而不是抛类型错误（那会让人以为框架坏了）。
     */
    public function test_add_many_tolerates_bad_class_names(): void
    {
        $m = new ExtensionManager;
        $m->addMany([
            'App\\Aimanong\\NoSuchExtensionClass',
            \stdClass::class,
            CoreTestExtension::class,
        ]);

        $this->assertSame(1, $m->count(), '只有合法的那个被加进来');
        $this->assertCount(2, $m->failures());
        $this->assertStringContainsString('类不存在', implode(' ', $m->failures()));
        $this->assertStringContainsString('未继承 Extension', implode(' ', $m->failures()));
    }

    /**
     * toArray() 是给 `/__ai/*` 与 `php artisan aimanong:extensions` 看的，
     * 形状必须稳定。
     */
    public function test_to_array_shape(): void
    {
        $m = new ExtensionManager;
        $m->add(new CoreTestExtension('alpha'));
        $m->register();
        $m->boot();

        $out = $m->toArray();

        // 形状是**列表**：每个扩展一条，附带 booted / error。
        // （不是 {'extensions': [...], 'booted': [...]} 那种字典 ——
        //   第一版断言写成了后者，红得对：我凭印象猜了形状。）
        $this->assertCount(1, $out);
        $this->assertSame('alpha', $out[0]['name']);
        $this->assertTrue($out[0]['booted']);
        $this->assertNull($out[0]['error']);
        // title() 没重写时默认等于 name()
        $this->assertSame('alpha', $out[0]['title']);
    }

    /**
     * 失败的扩展在 toArray 里要带 error ——
     * 否则 `php artisan aimanong:extensions` 只显示「没启动」，
     * 看不出为什么。
     */
    public function test_to_array_carries_error_for_failed_extension(): void
    {
        $m = new ExtensionManager;
        $m->add(new CoreTestExtension('boom', throwOnBoot: true));
        $m->register();
        $m->boot();

        $out = $m->toArray();

        $this->assertCount(1, $out);
        $this->assertFalse($out[0]['booted']);
        $this->assertStringContainsString('boot() 失败', (string) $out[0]['error']);
    }
}

/**
 * 测试用扩展。
 */
class CoreTestExtension extends Extension
{
    /** @var array<int, string> */
    public static array $registerOrder = [];

    /** @var array<string, bool> */
    public static array $booted = [];

    /**
     * @param  array<int, string>  $deps
     */
    public function __construct(
        protected string $id = 'test',
        protected array $deps = [],
        protected bool $throwOnRegister = false,
        protected bool $throwOnBoot = false,
    ) {
        static::$registerOrder = [];
        static::$booted = [];
    }

    public function name(): string
    {
        return $this->id;
    }

    /**
     * @return array<int, string>
     */
    public function dependencies(): array
    {
        return $this->deps;
    }

    public function register(): void
    {
        if ($this->throwOnRegister) {
            throw new \RuntimeException('register 炸了');
        }

        static::$registerOrder[] = $this->id;
    }

    public function boot(): void
    {
        if ($this->throwOnBoot) {
            throw new \RuntimeException('boot 炸了');
        }

        static::$booted[$this->id] = true;
    }
}
