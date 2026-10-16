<?php

declare(strict_types=1);

/*
 * Aimanong 配置（ThinkPHP 版）。
 *
 * 由 `php think aimanong:install` 发布到项目的 config/ 目录。
 * 与 Laravel 侧**同一份结构与键名** —— 同一套声明在两个框架下
 * 应该得到同样的行为，配置项分叉会让这件事不成立。
 */

return [
    /*
     * 后台 URL 前缀。
     */
    'prefix' => '/admin',

    /*
    |--------------------------------------------------------------------------
    | 多应用（多后台）
    |--------------------------------------------------------------------------
    |
    | 一个项目里跑多个互相隔离的后台。留空表示只用默认单后台。
    |
    | 与 Laravel 侧**同一套结构**（title / route.prefix / auth.guard /
    | auth.model），这样同一个项目的配置可以对照着看，换框架不用重新理解。
    |
    | 'applications' => [
    |     'admin' => [
    |         'title' => '总管理后台',
    |         'route' => ['prefix' => 'admin'],
    |         'auth'  => ['guard' => 'admin', 'model' => \Aimanong\ThinkPHP\Models\Administrator::class],
    |     ],
    |     'agent' => [
    |         'title' => '代理商后台',
    |         'route' => ['prefix' => 'agent'],
    |         'auth'  => ['guard' => 'agent', 'model' => \app\model\Agent::class],
    |     ],
    | ],
    |
    */
    'applications' => [],

    /*
     * 认证。
     *
     * ⚠️ ThinkPHP 侧的认证层**尚未实现**（见 ThinkRuntime 的注释）。
     * 这里的配置项先按 Laravel 侧的结构预留，避免以后改名造成迁移成本；
     * 但现在即使配了也不会生效 —— 不会假装生效。
     */
    'auth' => [
        // 目前 TP 侧还没有内置管理员模型（认证层未实现），
        // 这里留空字符串而不是指向一个不存在的类 —— 不谎报。
        'model' => '',
        'guard' => 'admin',
        'session_key' => 'aimanong_admin_id',

        /*
         * 是否启用 RBAC（角色 / 权限）。
         *
         * ⚠️ 默认 **false** —— 与 Laravel 侧一致，RBAC 是 opt-in 的。
         * 关闭时**不做任何权限判定**（一切放行）。
         *
         * 打开后：超管角色绕过一切；其余按「用户 → 角色 → 权限节点」判定。
         * 打开前请确认已有角色与权限数据，否则所有人都会被拒（403）。
         */
        'rbac' => false,

        /*
         * 登录限流（防暴力破解）。
         *
         * 两个维度叠加，至少保证「同一 IP 对同一账号」始终受限 ——
         * 只按 IP 限流的话，攻击者换 IP 就能继续猜同一个账号。
         *
         *   0 表示关闭该维度
         */
        'throttle' => [
            'enable' => true,
            'per_ip' => 20,
            'per_account' => 5,
            'window' => 60,
        ],
    ],

    /*
     * 数据字典（配置声明式）。
     *
     * 与 Laravel 侧同一格式：
     *   'code' => ['name' => '名称', 'items' => ['value' => '标签']]
     */
    'dict' => [
        'declarations' => [],
    ],

    /*
     * 上传能力描述（前端需要知道的上传参数）。
     */
    'upload' => [
        'disk' => 'public',
        'directory' => 'uploads',
        'endpoint' => '/admin/upload',
        'url_template' => '/uploads/{path}',
        'image_max_size' => 5120,
        'max_size' => 10240,
    ],

    /*
     * 界面。
     */
    'ui' => [
        'brand' => 'Aimanong',
    ],

    /*
    |--------------------------------------------------------------------------
    | 扩展（插件）
    |--------------------------------------------------------------------------
    |
    | 扩展**必须显式登记**，框架不会自动发现 —— 与 Laravel 侧同一个键、
    | 同一套时序（addMany → register → boot）。
    |
    | 例：装了 aimanong/region 之后：
    |
    |     'extensions' => [
    |         \Aimanong\Region\RegionExtension::class,
    |     ],
    |
    | ⚠️ 不登记的话扩展根本不会 boot：
    |   · `FieldType::fieldViewFor('region')` 是 null → 字段渲染不出来
    |   · `$form->region()` 编译失败 —— 而报错指向别处，不好查
    |
    | 在此之前 TP 侧**完全没有这个机制**，而 `capabilities.json` 的
    | `extension` 段一直在教 AI 怎么写扩展 —— 对 TP 用户是一纸空文。
    */
    'extensions' => [
        // App\Aimanong\Extensions\DemoExtension::class,
    ],
];
