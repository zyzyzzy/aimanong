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
    /**
     * 注册后台路由。
     *
     * ⚠️ 必须放在 boot() 而不是 register() ——
     * register() 阶段路由还不可用，注册了也不会生效，
     * 表现是 TP 把它当成默认的「控制器/方法」路由去解析
     * （实测：`/admin` 报「控制器不存在: app\controller\Admin」）。
     */
    public function boot(): void
    {
        $routeFile = __DIR__.'/../route/admin.php';

        if (! is_file($routeFile)) {
            return;
        }

        \think\facade\Route::group((new ThinkRuntime)->urlPrefix(), function () use ($routeFile): void {
            include $routeFile;
        });

        // 控制台命令 —— TP 的 Service::commands() 会在 console 启动时挂上
        $this->commands([
            \Aimanong\ThinkPHP\Console\InstallCommand::class,
            \Aimanong\ThinkPHP\Console\VerifyCommand::class,
            \Aimanong\ThinkPHP\Console\SchemaCommand::class,
        ]);
    }

    public function register(): void
    {
        Drivers::registerIntrospector(new ThinkIntrospector);
        Drivers::registerDictionary(new ThinkDictionary);
        Drivers::registerPermissionChecker(new ThinkPermissionChecker);
        Drivers::registerUploadSpec(new ThinkUploadSpec);

        // 公开入口 Aimanong\Aimanong 在 core，它需要的框架设施接在这里
        \Aimanong\Aimanong::bindRuntime(new ThinkRuntime);
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
        \Aimanong\Aimanong::bindRuntime(new ThinkRuntime);
    }
}
