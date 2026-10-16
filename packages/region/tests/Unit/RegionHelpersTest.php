<?php

declare(strict_types=1);

namespace Aimanong\Region\Tests\Unit;

use Aimanong\Region\Region;
use PHPUnit\Framework\TestCase;

/**
 * 全局辅助函数。
 *
 * `src/helpers.php` 走 composer 的 `files` 自动加载 —— 这类文件
 * 一旦函数名/签名与文档不符，使用者要到运行期才发现。
 */
class RegionHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        Region::reset();
        parent::tearDown();
    }

    public function test_helpers_are_loaded(): void
    {
        // composer files autoload 生效的直接证据
        $this->assertTrue(function_exists('region_name'));
        $this->assertTrue(function_exists('region_provinces'));
    }

    public function test_region_name(): void
    {
        $this->assertSame('北京市/市辖区/东城区', region_name('110101'));
        $this->assertSame('浙江省', region_name('330000'));

        // 未知编码 → null（不抛异常：前端可能拿着脏数据）
        $this->assertNull(region_name('999999'));

        // null 直接透传（避免调用方每次判空）
        $this->assertNull(region_name(null));
    }

    public function test_region_provinces(): void
    {
        $provinces = region_provinces();

        $this->assertCount(5, $provinces);
        $this->assertSame(['code', 'name'], array_keys($provinces[0]));
    }

    /**
     * 辅助函数必须跟随 useData() 注入的数据 —— 而不是固守内置数据。
     */
    public function test_helpers_follow_injected_data(): void
    {
        Region::useData([
            ['code' => 'Z', 'name' => '注入省', 'children' => []],
        ]);

        $this->assertCount(1, region_provinces());
        $this->assertSame('注入省', region_name('Z'));
    }
}
