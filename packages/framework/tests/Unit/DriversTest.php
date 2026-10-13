<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Auth\PermissionGateChecker;
use Aimanong\Contracts\DictionarySource;
use Aimanong\Contracts\PermissionChecker;
use Aimanong\Contracts\UploadSpec;
use Aimanong\Drivers\Eloquent\EloquentIntrospector;
use Aimanong\Exceptions\DriverNotRegisteredException;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Foundation\Upload\Uploader;
use Aimanong\Support\Drivers;
use PHPUnit\Framework\TestCase;

/**
 * 驱动注册表测试。
 *
 * 这是 core 与具体框架之间的唯一接缝，也是多框架适配能成立的前提，
 * 所以它的**两类缺失语义**必须被钉死：
 *
 *   - 数据字典 / 权限 / 上传 → 缺了就抛（声明了能力却兑现不了 = 幽灵能力）
 *   - Schema 内省 → 缺了返回 null（这项检查本就尽力而为）
 *
 * 两者混为一谈，要么无谓报错，要么静默产出错误 schema。
 */
class DriversTest extends TestCase
{
    /**
     * 每个用例前清空注册表，用例后再装回 bootstrap 注册的那套。
     */
    protected function setUp(): void
    {
        Drivers::reset();
    }

    protected function tearDown(): void
    {
        Drivers::reset();
        Drivers::registerIntrospector(new EloquentIntrospector);
        Drivers::registerDictionary(new Dictionary);
        Drivers::registerPermissionChecker(new PermissionGateChecker);
        Drivers::registerUploadSpec(new Uploader);
    }

    // ── 缺驱动：必须抛，不能降级 ──────────────────

    public function test_dictionary_throws_when_missing(): void
    {
        $this->expectException(DriverNotRegisteredException::class);

        Drivers::dictionary();
    }

    public function test_permissions_throws_when_missing(): void
    {
        $this->expectException(DriverNotRegisteredException::class);

        Drivers::permissions();
    }

    public function test_upload_throws_when_missing(): void
    {
        $this->expectException(DriverNotRegisteredException::class);

        Drivers::upload();
    }

    /**
     * 异常必须能让 AI 一次改对（宪法第六条 错误可自愈）。
     */
    public function test_missing_driver_exception_is_ai_readable(): void
    {
        try {
            Drivers::dictionary();
            $this->fail('应当抛 DriverNotRegisteredException');
        } catch (DriverNotRegisteredException $e) {
            $ctx = $e->toArray();

            $this->assertSame('DRIVER_NOT_REGISTERED', $ctx['error']);
            $this->assertSame('DictionarySource', $ctx['driver']);
            $this->assertNotEmpty($ctx['hint']);
            $this->assertNotEmpty($ctx['example']);
            $this->assertNotEmpty($ctx['docs']);
        }
    }

    public function test_has_flags_reflect_registration(): void
    {
        $this->assertFalse(Drivers::hasDictionary());
        $this->assertFalse(Drivers::hasPermissionChecker());
        $this->assertFalse(Drivers::hasUploadSpec());
        $this->assertFalse(Drivers::hasIntrospector());

        Drivers::registerDictionary(new Dictionary);
        Drivers::registerPermissionChecker(new PermissionGateChecker);
        Drivers::registerUploadSpec(new Uploader);

        $this->assertTrue(Drivers::hasDictionary());
        $this->assertTrue(Drivers::hasPermissionChecker());
        $this->assertTrue(Drivers::hasUploadSpec());
    }

    /**
     * 内省是**刻意不同**的一类：缺了返回 null 而不是抛。
     * 幽灵列检测在无数据库环境下本就跳过。
     */
    public function test_introspector_returns_null_instead_of_throwing(): void
    {
        $this->assertNull(Drivers::introspector());
    }

    // ── 注册后可用 ──────────────────────────────

    public function test_registered_drivers_are_returned(): void
    {
        $dictionary = new Dictionary;
        $gate = new PermissionGateChecker;
        $upload = new Uploader;

        Drivers::registerDictionary($dictionary);
        Drivers::registerPermissionChecker($gate);
        Drivers::registerUploadSpec($upload);

        $this->assertInstanceOf(DictionarySource::class, Drivers::dictionary());
        $this->assertSame($dictionary, Drivers::dictionary());
        $this->assertInstanceOf(PermissionChecker::class, Drivers::permissions());
        $this->assertSame($gate, Drivers::permissions());
        $this->assertInstanceOf(UploadSpec::class, Drivers::upload());
        $this->assertSame($upload, Drivers::upload());
    }

    public function test_registered_names_lists_what_is_available(): void
    {
        $this->assertSame([], Drivers::registeredNames());

        Drivers::registerDictionary(new Dictionary);

        $this->assertSame(['DictionarySource'], Drivers::registeredNames());
    }

    public function test_reset_clears_everything(): void
    {
        Drivers::registerDictionary(new Dictionary);
        Drivers::registerUploadSpec(new Uploader);

        Drivers::reset();

        $this->assertSame([], Drivers::registeredNames());
    }
}
