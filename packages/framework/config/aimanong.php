<?php

declare(strict_types=1);
use Aimanong\Foundation\Upload\Uploader;
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

        /*
         * 数据字典：把枚举变成一等公民。
         *
         * 两种声明方式，读取只走 Dictionary 一个入口：
         *   1. declarations（代码，优先级更高、可 git diff、AI 能读）
         *   2. 后台「数据字典」维护（运营可改文案）
         *
         * declarations 里的字典在后台**只读**：运维误删一个
         * 被代码引用的字典，会让相关页面瞬间变成空下拉框。
         *
         * 写法：
         *   'declarations' => [
         *       'order_status' => [
         *           'name'  => '订单状态',
         *           'items' => [
         *               'pending' => '待付款',
         *               ['value' => 'paid', 'label' => '已付款', 'color' => 'success'],
         *           ],
         *       ],
         *   ],
         *
         * 用法：$form->select('status')->dict('order_status');
         *       $grid->column('status', '状态')->dict('order_status');
         */
        'dict' => [
            'enable' => env('AIMANONG_DICT', true),
            'cache_ttl' => (int) env('AIMANONG_DICT_TTL', 600),
            'declarations' => [],
        ],

        /*
         * 管理员账号管理界面。
         *
         * 关掉只隐藏界面，不影响认证 —— 用户可能想用自己的 User 模型与界面。
         */
        'admin_user' => [
            'enable' => env('AIMANONG_ADMIN_USER', true),
        ],

        /*
         * 个人中心：改资料 / 改密码 / 看我的权限与操作记录。
         *
         * 关掉后顶栏不再出现「个人中心」入口，路由 404。
         */
        'profile' => [
            'enable' => env('AIMANONG_PROFILE', true),
        ],

        /*
         * 文件上传。
         *
         * 用法：$form->image('avatar')->label('头像')->maxSize(2048);
         *       $form->images('gallery')->label('图集');
         *       $form->file('contract')->label('合同附件')->accept('pdf,docx');
         *
         * serve 决定「文件怎么被读出来」：
         *   auto （默认）本地磁盘走框架读取路由，免 storage:link；
         *                有公开 url 的云磁盘走 Storage::url()
         *   route        始终走框架路由（需要登录才能读，适合私有附件）
         *   url          始终走 Storage::url()（CDN / S3 场景）
         *
         * ⚠️ 默认**不包含 svg**。
         * SVG 可以内嵌 <script>，而框架路由是同源读取，
         * 打开一个恶意 SVG 就等于同源 XSS。要收就必须自己打开，
         * 并确保在独立域名/CSP 下使用。
         */
        'upload' => [
            'enable' => env('AIMANONG_UPLOAD', true),
            'disk' => env('AIMANONG_UPLOAD_DISK', 'public'),
            'directory' => env('AIMANONG_UPLOAD_DIR', 'uploads'),
            'serve' => env('AIMANONG_UPLOAD_SERVE', 'auto'),

            // 单位 KB
            'max_size' => (int) env('AIMANONG_UPLOAD_MAX', Uploader::DEFAULT_MAX_SIZE),
            'image_max_size' => (int) env('AIMANONG_UPLOAD_IMAGE_MAX', Uploader::DEFAULT_IMAGE_MAX_SIZE),

            // 默认值定义在 Uploader 常量里（单一来源）
            'image_extensions' => Uploader::DEFAULT_IMAGE_EXTENSIONS,
            'file_extensions' => Uploader::DEFAULT_FILE_EXTENSIONS,
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
