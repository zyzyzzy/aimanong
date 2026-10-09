<?php

declare(strict_types=1);

namespace Aimanong\Region;

use Aimanong\Extend\Extension;
use Aimanong\Region\Fields\RegionField;

/**
 * 省市区扩展。
 *
 * 这是**首个官方扩展**，同时用于验证插件机制的真实可用性：
 *   1. 作为独立 Composer 包存在（不修改框架核心）
 *   2. 注入自定义字段类型 region
 *   3. 注册自己的路由（提供级联查询接口）
 */
class RegionExtension extends Extension
{
    public function name(): string
    {
        return 'region';
    }

    public function title(): string
    {
        return '省市区选择器';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return '中国省市区三级联动字段，支持省市/省市区两种层级';
    }

    /**
     * 注册阶段：注入字段类型。
     */
    public function register(): void
    {
        $this->field(RegionField::class, 'region', 'string', 'string');
    }

    /**
     * 启动阶段：注册级联查询路由。
     */
    public function boot(): void
    {
        $this->routes(function (): void {
            \Illuminate\Support\Facades\Route::get('cities/{province}', function (string $province) {
                return response()->json(['data' => Region::cities($province)]);
            });

            \Illuminate\Support\Facades\Route::get('districts/{city}', function (string $city) {
                return response()->json(['data' => Region::districts($city)]);
            });

            \Illuminate\Support\Facades\Route::get('provinces', function () {
                return response()->json(['data' => Region::provinces()]);
            });
        });
    }
}
