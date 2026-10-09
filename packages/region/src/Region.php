<?php

declare(strict_types=1);

namespace Aimanong\Region;

/**
 * 省市区数据源。
 *
 * 内置少量示例数据用于演示；生产环境可替换为完整数据集
 * （通过 Region::useData() 注入自定义的树）。
 *
 * 数据形态：
 *   [['code' => '110000', 'name' => '北京市', 'children' => [...]]]
 */
class Region
{
    /**
     * @var array<int, array<string, mixed>>|null
     */
    protected static ?array $custom = null;

    /**
     * 注入自定义数据（完整省市区数据）。
     *
     * @param  array<int, array<string, mixed>>  $tree
     */
    public static function useData(array $tree): void
    {
        static::$custom = $tree;
    }

    /**
     * 完整树。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tree(): array
    {
        return static::$custom ?? static::builtin();
    }

    /**
     * 省份列表。
     *
     * @return array<int, array{code: string, name: string}>
     */
    public static function provinces(): array
    {
        return array_map(
            fn (array $p): array => ['code' => $p['code'], 'name' => $p['name']],
            static::tree()
        );
    }

    /**
     * 某省下的市。
     *
     * @return array<int, array{code: string, name: string}>
     */
    public static function cities(string $provinceCode): array
    {
        foreach (static::tree() as $p) {
            if ($p['code'] === $provinceCode) {
                return array_map(
                    fn (array $c): array => ['code' => $c['code'], 'name' => $c['name']],
                    $p['children'] ?? []
                );
            }
        }

        return [];
    }

    /**
     * 某市下的区县。
     *
     * @return array<int, array{code: string, name: string}>
     */
    public static function districts(string $cityCode): array
    {
        foreach (static::tree() as $p) {
            foreach ($p['children'] ?? [] as $c) {
                if ($c['code'] === $cityCode) {
                    return array_map(
                        fn (array $d): array => ['code' => $d['code'], 'name' => $d['name']],
                        $c['children'] ?? []
                    );
                }
            }
        }

        return [];
    }

    /**
     * 通过编码取完整名称（如 110101 → 北京市/市辖区/东城区）。
     */
    public static function fullName(string $code): ?string
    {
        foreach (static::tree() as $p) {
            if ($p['code'] === $code) {
                return $p['name'];
            }

            foreach ($p['children'] ?? [] as $c) {
                if ($c['code'] === $code) {
                    return $p['name'].'/'.$c['name'];
                }

                foreach ($c['children'] ?? [] as $d) {
                    if ($d['code'] === $code) {
                        return $p['name'].'/'.$c['name'].'/'.$d['name'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * 内置示例数据。
     *
     * 说明：这是**演示用的小数据集**（每省仅列部分城市）。
     * 完整数据请通过 useData() 注入 —— 完整数据集约 3400 条，
     * 不适合打包在框架仓库里。
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function builtin(): array
    {
        return [
            [
                'code' => '110000', 'name' => '北京市',
                'children' => [
                    [
                        'code' => '110100', 'name' => '市辖区',
                        'children' => [
                            ['code' => '110101', 'name' => '东城区'],
                            ['code' => '110102', 'name' => '西城区'],
                            ['code' => '110105', 'name' => '朝阳区'],
                            ['code' => '110106', 'name' => '丰台区'],
                            ['code' => '110108', 'name' => '海淀区'],
                        ],
                    ],
                ],
            ],
            [
                'code' => '310000', 'name' => '上海市',
                'children' => [
                    [
                        'code' => '310100', 'name' => '市辖区',
                        'children' => [
                            ['code' => '310101', 'name' => '黄浦区'],
                            ['code' => '310104', 'name' => '徐汇区'],
                            ['code' => '310105', 'name' => '长宁区'],
                            ['code' => '310106', 'name' => '静安区'],
                            ['code' => '310115', 'name' => '浦东新区'],
                        ],
                    ],
                ],
            ],
            [
                'code' => '440000', 'name' => '广东省',
                'children' => [
                    [
                        'code' => '440100', 'name' => '广州市',
                        'children' => [
                            ['code' => '440103', 'name' => '荔湾区'],
                            ['code' => '440104', 'name' => '越秀区'],
                            ['code' => '440105', 'name' => '海珠区'],
                            ['code' => '440106', 'name' => '天河区'],
                        ],
                    ],
                    [
                        'code' => '440300', 'name' => '深圳市',
                        'children' => [
                            ['code' => '440303', 'name' => '罗湖区'],
                            ['code' => '440304', 'name' => '福田区'],
                            ['code' => '440305', 'name' => '南山区'],
                            ['code' => '440306', 'name' => '宝安区'],
                        ],
                    ],
                ],
            ],
            [
                'code' => '330000', 'name' => '浙江省',
                'children' => [
                    [
                        'code' => '330100', 'name' => '杭州市',
                        'children' => [
                            ['code' => '330102', 'name' => '上城区'],
                            ['code' => '330103', 'name' => '下城区'],
                            ['code' => '330106', 'name' => '西湖区'],
                            ['code' => '330108', 'name' => '滨江区'],
                        ],
                    ],
                ],
            ],
            [
                'code' => '510000', 'name' => '四川省',
                'children' => [
                    [
                        'code' => '510100', 'name' => '成都市',
                        'children' => [
                            ['code' => '510104', 'name' => '锦江区'],
                            ['code' => '510105', 'name' => '青羊区'],
                            ['code' => '510107', 'name' => '武侯区'],
                            ['code' => '510108', 'name' => '成华区'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
