<?php

declare(strict_types=1);
use Aimanong\Models\Administrator;

return [

    /*
    |--------------------------------------------------------------------------
    | 路由前缀
    |--------------------------------------------------------------------------
    */
    'route' => [
        'prefix' => env('AIMANONG_PREFIX', 'admin'),
        'domain' => env('AIMANONG_DOMAIN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 认证
    |--------------------------------------------------------------------------
    |
    | Laravel 11+ 骨架不再包含 config/hashing.php，
    | 因此 rehash_on_login 的默认值在此处约定，不依赖框架配置文件。
    |
    */
    'auth' => [
        'enable' => true,
        'guard' => 'admin',
        'provider' => 'admin',
        'model' => Administrator::class,

        // 登录时按需重新哈希密码（Laravel 11+ 契约要求）
        'rehash_on_login' => false,

        // 是否启用内置 RBAC（角色/权限）。关闭时所有操作放行。
        'rbac' => env('AIMANONG_RBAC', false),

        'except' => [
            'auth/login',
            'auth/logout',
            // 框架内置资源必须免登录 —— 登录页自身要用 LOGO
            'assets',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI 能力（M3）
    |--------------------------------------------------------------------------
    |
    | 自省接口默认仅 local/debug 环境开启；生产开启需配置 token。
    |
    */
    'ai' => [
        'enable' => env('AIMANONG_AI_ENABLE', null),
        'token' => env('AIMANONG_AI_TOKEN'),
        'route_prefix' => '__ai',
    ],

    /*
    |--------------------------------------------------------------------------
    | 多应用（多后台）
    |--------------------------------------------------------------------------
    |
    | 一个项目里跑多个互相隔离的后台。留空表示只用默认单后台。
    |
    | 'applications' => [
    |     'admin' => [
    |         'title' => '运营后台',
    |         'route' => ['prefix' => 'admin'],
    |         'auth' => ['guard' => 'admin', 'model' => App\Models\Administrator::class],
    |     ],
    |     'merchant' => [
    |         'title' => '商家后台',
    |         'route' => ['prefix' => 'merchant'],
    |         'auth' => ['guard' => 'merchant', 'model' => App\Models\Merchant::class],
    |     ],
    | ],
    |
    */
    'applications' => [],

    /*
    |--------------------------------------------------------------------------
    | 扩展（插件）
    |--------------------------------------------------------------------------
    |
    | 列出要启用的扩展类名。扩展会自动注册其 Resource、路由与视图。
    |
    */
    /*
     * 界面配置。
     */
    'ui' => [
        // 控制台标题与页脚显示的品牌名（换成你自己的系统名）
        'brand' => env('AIMANONG_BRAND', 'Aimanong'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 基座功能（P0）
    |--------------------------------------------------------------------------
    |
    | 「带地基的平台」开箱即用的能力，由框架内置 Resource 提供，
    | 用户无需写任何代码。关掉即整体隐藏（菜单、权限、路由一起消失）。
    |
    */
    'foundation' => [

        /*
         * 操作日志：谁在什么时候改了哪条数据。
         *
         * 只记写操作（POST/PUT/PATCH/DELETE），不记 GET ——
         * 否则刷新一下列表就是一条日志，真正重要的记录会被淹没。
         *
         * except：按路径前缀排除噪音（不含路由前缀，框架自动补）。
         */
        'operation_log' => [
            'enable' => env('AIMANONG_OPERATION_LOG', true),
            'except' => [
                'api/ui/preferences',
            ],
            'keep_days' => (int) env('AIMANONG_OPERATION_LOG_KEEP_DAYS', 90),
        ],

        /*
         * 登录日志：成功与失败都记。
         * 只记成功等于放弃了「有人在爆破」这条线索。
         */
        'login_log' => [
            'enable' => env('AIMANONG_LOGIN_LOG', true),
            'keep_days' => (int) env('AIMANONG_LOGIN_LOG_KEEP_DAYS', 180),
        ],
    ],

    'extensions' => [
        // App\Aimanong\Extensions\DemoExtension::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 前端资源
    |--------------------------------------------------------------------------
    */
    'assets' => [
        'entry' => 'vendor/aimanong/app.js',
    ],
];
