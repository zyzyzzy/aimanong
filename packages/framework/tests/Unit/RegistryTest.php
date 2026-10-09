<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Resource 注册表容错测试。
 *
 * 背景：用户的 ServiceProvider 里可能残留已删除 Resource 的注册。
 * 早期实现会抛异常 —— 那会导致**整个应用崩溃**（连 ai:verify 都跑不了），
 * 用户无法自救。现在改为记录失败而非抛异常。
 */
class RegistryTest extends TestCase
{
    public function test_missing_class_is_recorded_not_thrown(): void
    {
        $registry = new Registry;

        // 不应抛异常
        $registry->register('App\\Aimanong\\NotExistResource');

        $this->assertSame(0, $registry->count());
        $this->assertCount(1, $registry->failures());
        $this->assertStringContainsString('NotExistResource', $registry->failures()[0]);
    }

    public function test_class_not_extending_resource_is_recorded(): void
    {
        $registry = new Registry;

        // stdClass 存在但未继承 Resource
        $registry->register(\stdClass::class);

        $this->assertSame(0, $registry->count());
        $this->assertCount(1, $registry->failures());
        $this->assertStringContainsString('未继承', $registry->failures()[0]);
    }

    public function test_multiple_failures_are_all_recorded(): void
    {
        $registry = new Registry;

        $registry->register('No\\Such\\ClassA');
        $registry->register('No\\Such\\ClassB');

        $this->assertCount(2, $registry->failures());
    }

    public function test_successful_registration_has_no_failures(): void
    {
        $registry = new Registry;
        $registry->register(Fixtures\ArticleResource::class);

        $this->assertSame(1, $registry->count());
        $this->assertSame([], $registry->failures());
        $this->assertSame(Fixtures\ArticleResource::class, $registry->find('articles'));
    }
}
