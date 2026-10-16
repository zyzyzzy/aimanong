<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\CapabilitiesRuntime;
use Aimanong\Support\PermissionNodes;
use PHPUnit\Framework\TestCase;

/**
 * 能力清单的驱动侧输入。
 *
 * `Capabilities`（687 行说明文本）已在 core，两端共用；
 * 它只通过这个运行时取「只有驱动才知道」的 5 个值。
 *
 * 这里锁的是**未注册时的退化行为** —— 纯静态自省、单元测试、
 * CLI 早期都要能读，不能因为「容器还没起来」就抛异常。
 * 而那正是 `config()` 在没有 Application 实例时会做的事。
 */
class CapabilitiesRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        CapabilitiesRuntime::reset();
        parent::tearDown();
    }

    public function test_defaults_when_no_driver_registered(): void
    {
        // 开关退化为传入的默认值（而不是抛异常）
        $this->assertTrue(CapabilitiesRuntime::flag('aimanong.whatever'));
        $this->assertFalse(CapabilitiesRuntime::flag('aimanong.whatever', false));

        // 版本表为空（不假装知道）
        $this->assertSame([], CapabilitiesRuntime::frameworkVersions());

        // 字典为空数组
        $this->assertSame([], CapabilitiesRuntime::dictionaryList());

        // RBAC 默认关闭
        $this->assertFalse(CapabilitiesRuntime::rbacEnabled());

        // 动作表退化为 core 声明的常量 —— 只有它是不依赖驱动的
        $this->assertSame(PermissionNodes::ACTIONS, CapabilitiesRuntime::rbacActions());
    }

    public function test_driver_values_are_used(): void
    {
        CapabilitiesRuntime::provideConfigReader(
            static fn (string $key, mixed $default = null): mixed => $key === 'on' ? true : $default
        );
        CapabilitiesRuntime::provideFrameworkVersion('laravel', '12.0.0');
        CapabilitiesRuntime::provideDictionaries(['x' => ['name' => 'X']]);
        CapabilitiesRuntime::provideRbac(true, ['index', 'show']);

        $this->assertTrue(CapabilitiesRuntime::flag('on'));
        $this->assertFalse(CapabilitiesRuntime::flag('off', false));
        $this->assertSame(['laravel' => '12.0.0'], CapabilitiesRuntime::frameworkVersions());
        $this->assertSame(['x' => ['name' => 'X']], CapabilitiesRuntime::dictionaryList());
        $this->assertTrue(CapabilitiesRuntime::rbacEnabled());
        $this->assertSame(['index', 'show'], CapabilitiesRuntime::rbacActions());
    }

    /**
     * 配置读取器抛异常时必须退化为默认值。
     *
     * 真实场景：`config()` 在没有 Application 实例时抛
     * `ReflectionException: Class "config" does not exist`。
     * 能力清单是**展示性**信息，不能因此崩掉 ——
     * （业务逻辑里的 config() 不许这么写，那会掩盖真实配置错误。）
     */
    public function test_config_reader_throwing_falls_back_to_default(): void
    {
        CapabilitiesRuntime::provideConfigReader(
            static function (): mixed {
                throw new \RuntimeException('Class "config" does not exist');
            }
        );

        $this->assertTrue(CapabilitiesRuntime::flag('anything'));
        $this->assertFalse(CapabilitiesRuntime::flag('anything', false));
    }

    /**
     * 多个驱动的版本要能共存（同一进程内先 Laravel 后 TP 之类）。
     */
    public function test_multiple_framework_versions(): void
    {
        CapabilitiesRuntime::provideFrameworkVersion('laravel', '12.0.0');
        CapabilitiesRuntime::provideFrameworkVersion('thinkphp', '8.1.4');

        $this->assertSame(
            ['laravel' => '12.0.0', 'thinkphp' => '8.1.4'],
            CapabilitiesRuntime::frameworkVersions()
        );
    }

    /**
     * reset() 之后必须回到「未注册」状态 ——
     * 静态状态会跨用例泄漏，测试之间必须能隔离。
     */
    public function test_reset_restores_defaults(): void
    {
        CapabilitiesRuntime::provideRbac(true, ['x']);
        CapabilitiesRuntime::provideFrameworkVersion('laravel', '12.0.0');
        CapabilitiesRuntime::reset();

        $this->assertFalse(CapabilitiesRuntime::rbacEnabled());
        $this->assertSame([], CapabilitiesRuntime::frameworkVersions());
        $this->assertSame(PermissionNodes::ACTIONS, CapabilitiesRuntime::rbacActions());
    }
}
