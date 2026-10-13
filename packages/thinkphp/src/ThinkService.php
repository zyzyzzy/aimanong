<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Support\Drivers;
use think\Service;

/**
 * Aimanong 的 ThinkPHP 服务。
 *
 * 在 ThinkPHP 的 `app/service.php` 里登记本类即可：
 *
 *     return [
 *         \Aimanong\ThinkPHP\ThinkService::class,
 *     ];
 *
 * ## 为什么必须有这一步
 *
 * core 里的 Form / Grid / Menu / Schema 只说「我需要一个
 * SchemaIntrospector / DictionarySource / PermissionChecker / UploadSpec」，
 * 具体是谁由框架包决定。不注册的话：
 *
 *   - 用 `->dict()` 的声明会抛 `DriverNotRegisteredException`
 *     （**这是刻意的**：声明了能力却兑现不了就是幽灵能力）
 *   - 幽灵列检测会静默跳过（这一项本就尽力而为）
 *
 * 也就是说注册不是可选的装饰，而是「声明能否兑现」的前提。
 */
class ThinkService extends Service
{
    public function register(): void
    {
        Drivers::registerIntrospector(new ThinkIntrospector);
        Drivers::registerDictionary(new ThinkDictionary);
        Drivers::registerPermissionChecker(new ThinkPermissionChecker);
        Drivers::registerUploadSpec(new ThinkUploadSpec);
    }

    /**
     * 不依赖 ThinkPHP 运行时的注册入口。
     *
     * 用于：单元测试、CLI 脚本、以及任何「只想编译声明、不想启动整个应用」
     * 的场景（如产物生成）。
     */
    public static function bootstrap(): void
    {
        Drivers::registerIntrospector(new ThinkIntrospector);
        Drivers::registerDictionary(new ThinkDictionary);
        Drivers::registerPermissionChecker(new ThinkPermissionChecker);
        Drivers::registerUploadSpec(new ThinkUploadSpec);
    }
}
