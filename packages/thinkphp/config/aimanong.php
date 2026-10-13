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
     * 权限节点。
     *
     * ThinkPHP 侧的权限判定是**配置驱动、默认拒绝**：
     * 列在这里的节点才算拥有。没配的就是 403 —— 不谎报。
     *
     * 完整的 RBAC（角色表 / 用户绑定 / 数据库）留到后续增量。
     *
     *   'permissions' => ['orders.index', 'orders.create'],
     */
    'permissions' => [],

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
];
