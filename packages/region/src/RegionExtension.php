<?php

declare(strict_types=1);

namespace Aimanong\Region;

use Aimanong\Extend\Extension;
use Aimanong\Region\Fields\RegionField;
use Illuminate\Support\Facades\Route;

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
     * 注册阶段：注入字段类型 + 自带前端 UI。
     *
     * ⚠️ fieldView / fieldScript 是**框架为插件提供的注入口**。
     *
     * 在此之前，本插件的三级联动 UI 是硬编码在框架核心的
     * `resource.blade.php` 里的 —— 那带来两个问题：
     *
     *   1. framework 隐式依赖 region：只装核心包的用户打开列表页
     *      直接 Fatal error（composer.json 里并没有 require region）
     *   2. **第三方插件没有出路**：想加自定义字段 UI，除了改框架源码
     *      别无他法
     *
     * 现在插件自己带着 UI 走，框架只负责在合适的字段类型上渲染它。
     */
    public function register(): void
    {
        $this->field(RegionField::class, 'region', 'string', 'string');
    }

    /**
     * 启动阶段：注册视图 + 前端片段 + 级联查询路由。
     */
    public function boot(): void
    {
        // 插件自带的视图（框架按字段类型 include 它们）
        $this->views(__DIR__.'/../resources/views', 'aimanong-region');

        // 字段「长什么样」
        $this->fieldView('region', 'aimanong-region::field');

        // 字段「逻辑怎么算」（在页面 setup() 作用域内执行）
        $this->fieldScript('aimanong-region::script');

        $this->routes(function (): void {
            Route::get('cities/{province}', function (string $province) {
                return response()->json(['data' => Region::cities($province)]);
            });

            Route::get('districts/{city}', function (string $city) {
                return response()->json(['data' => Region::districts($city)]);
            });

            Route::get('provinces', function () {
                return response()->json(['data' => Region::provinces()]);
            });
        });
    }
}
