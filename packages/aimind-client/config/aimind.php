<?php

return [
    /*
    | Aimind 平台地址
    |
    | Phase 0: http://127.0.0.1:8999（本地）
    | Phase 2: https://aimind.aimanong.com（云端）
    */
    'url' => env('AIMIND_URL', 'http://127.0.0.1:8999'),

    /*
    | API Key（Phase 2 需要，Phase 0 留空）
    */
    'api_key' => env('AIMIND_API_KEY'),

    /*
    | 是否启用 Aimind 集成
    */
    'enabled' => env('AIMIND_ENABLED', true),

    /*
    | 请求超时（秒）
    */
    'timeout' => (int) env('AIMIND_TIMEOUT', 10),
];
