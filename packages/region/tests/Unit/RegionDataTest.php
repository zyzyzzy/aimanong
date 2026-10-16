<?php

declare(strict_types=1);

namespace Aimanong\Region\Tests\Unit;

use Aimanong\Region\Region;
use PHPUnit\Framework\TestCase;

/**
 * 省市区数据层。
 *
 * ## 为什么这个包以前一条测试都没有
 *
 * `aimanong/region` 是**已发布**的官方扩展（当时 v1.0.1），此前：
 * 0 单测、0 phpstan、0 CI —— 3451 行级的驱动都有门禁，这个包没有。
 * 2026-10-16 审计把「零覆盖的已发布包」列为 P2。
 *
 * ## 顺带钉住一个真实事故
 *
 * `README.md` 标题写「内置数据**只有 3 个省**」，正文写「只有 **5 个省**」——
 * 两处自相矛盾，因为标题是凭印象写的上一版（README 自己第 17 行
 * 还记着这次事故）。`framework/README.md` 又抄了错的 3。
 *
 * 数字写死在文档里就会漂。所以这里把它**钉成断言**：
 * 文档改了数据、或数据改了文档没跟上，这条会红。
 */
class RegionDataTest extends TestCase
{
    protected function tearDown(): void
    {
        // ⚠️ 不能用 useData([]) 复位 —— 空数组不是 null，
        // `$custom ?? builtin()` 不会回落，内置数据会永久消失。
        // 这正是补单测时发现的 bug，见 Region::reset() 的注释。
        Region::reset();
        parent::tearDown();
    }

    /**
     * README 对外宣称的数字 —— 改数据必须同时改文档。
     */
    public function test_builtin_size_matches_documented_numbers(): void
    {
        $tree = Region::tree();

        $nodes = 0;
        foreach ($tree as $p) {
            $nodes++;

            foreach ($p['children'] ?? [] as $c) {
                $nodes++;

                foreach ($c['children'] ?? [] as $d) {
                    $nodes++;
                }
            }
        }

        $this->assertCount(5, $tree, 'README 说内置 5 个省');
        $this->assertSame(37, $nodes, 'README 说内置数据共 37 个节点');
    }

    /**
     * 文档里的省份名字必须真的在数据里 —— 防止「文档写了一套、数据是另一套」。
     */
    public function test_documented_province_names_exist(): void
    {
        $names = array_column(Region::tree(), 'name');

        foreach (['北京市', '上海市', '广东省', '浙江省', '四川省'] as $name) {
            $this->assertContains($name, $names, "README 提到的「{$name}」不在数据里");
        }
    }

    public function test_provinces_returns_code_and_name_only(): void
    {
        $provinces = Region::provinces();

        $this->assertNotEmpty($provinces);

        foreach ($provinces as $p) {
            $this->assertSame(['code', 'name'], array_keys($p), '省份只应下发 code/name，不带 children');
        }
    }

    public function test_cities_and_districts(): void
    {
        // 广东省有两个市（广州市 / 深圳市）
        $cities = Region::cities('440000');
        $this->assertCount(2, $cities);
        $this->assertSame('广州市', $cities[0]['name']);

        $districts = Region::districts('440300');
        $this->assertContains('南山区', array_column($districts, 'name'));

        // 北京市在市辖区下有 5 个区
        $this->assertCount(5, Region::districts('110100'));
    }

    public function test_unknown_codes_return_empty_array(): void
    {
        $this->assertSame([], Region::cities('999999'));
        $this->assertSame([], Region::districts('999999'));
    }

    public function test_full_name_for_three_levels(): void
    {
        $this->assertSame('广东省', Region::fullName('440000'));
        $this->assertSame('广东省/深圳市', Region::fullName('440300'));
        $this->assertSame('广东省/深圳市/南山区', Region::fullName('440305'));

        $this->assertNull(Region::fullName('999999'));
    }

    /**
     * useData() 必须能整体替换数据源（生产环境注入完整数据集）。
     */
    public function test_use_data_replaces_whole_tree(): void
    {
        Region::useData([
            ['code' => 'X', 'name' => '测试省', 'children' => [
                ['code' => 'X1', 'name' => '测试市', 'children' => [
                    ['code' => 'X11', 'name' => '测试区'],
                ]],
            ]],
        ]);

        $provinces = Region::provinces();
        $this->assertCount(1, $provinces);
        $this->assertSame('测试省', $provinces[0]['name']);
        $this->assertSame('测试省/测试市/测试区', Region::fullName('X11'));
        $this->assertSame([], Region::cities('440000'), '注入后不该还能查到内置数据');
    }

    /**
     * reset() 必须能拿回内置数据 —— 这是这个 bug 的回归锁。
     *
     * 原先 `useData([])` 之后**没有任何办法**恢复：
     * `$custom = []` 不是 null，`??` 不回落，内置数据永久消失。
     */
    public function test_reset_restores_builtin_after_empty_injection(): void
    {
        Region::useData([]);
        $this->assertSame([], Region::provinces(), '注入空数组后当然是空的');

        Region::reset();
        $this->assertCount(5, Region::provinces(), 'reset() 必须拿回内置 5 省');
        $this->assertSame('北京市/市辖区/东城区', Region::fullName('110101'));
    }

    /**
     * 数据完整性：编码唯一、每层都不缺 code/name。
     *
     * 这类问题在「级联下拉」里表现为「某个选项点了没反应」，
     * 而且不会报错 —— 正是最该用断言兜住的那类。
     */
    public function test_data_integrity(): void
    {
        $seen = [];

        $walk = function (array $nodes, int $depth) use (&$walk, &$seen): void {
            foreach ($nodes as $n) {
                $this->assertArrayHasKey('code', $n, "第 {$depth} 层有节点缺 code");
                $this->assertArrayHasKey('name', $n, "第 {$depth} 层有节点缺 name");
                $this->assertNotSame('', $n['code'], '编码不能为空');
                $this->assertNotSame('', $n['name'], '名称不能为空');
                $this->assertArrayNotHasKey($n['code'], $seen, "编码 {$n['code']} 重复");

                $seen[$n['code']] = true;

                $walk($n['children'] ?? [], $depth + 1);
            }
        };

        $walk(Region::tree(), 1);

        $this->assertGreaterThan(0, count($seen));
    }
}
