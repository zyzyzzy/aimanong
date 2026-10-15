<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Aimanong;
use Aimanong\Support\Drivers;
use Aimanong\ThinkPHP\Application\ThinkApplication;
use Aimanong\ThinkPHP\Console\InstallCommand;
use Aimanong\ThinkPHP\Console\PermissionCommand;
use Aimanong\ThinkPHP\Console\SchemaCommand;
use Aimanong\ThinkPHP\Console\VerifyCommand;
use think\facade\Route;
use think\middleware\SessionInit;
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

        $runtime = new ThinkRuntime;
        $apps = $runtime->application();

        if ($apps->enabled()) {
            /*
             * 多应用：每个后台各自注册一份路由（前缀不同）。
             *
             * 同一个 route/admin.php 被 include 多次是**有意为之** ——
             * 它只声明相对路径，具体前缀由外层的 group 决定。
             * 这样「一套路由定义服务多个后台」，与 Laravel 侧一致。
             */
            foreach ($apps->names() as $name) {
                Route::group($runtime->prefixOf($name), function () use ($routeFile): void {
                    include $routeFile;
                });
            }
        } else {
            // 单应用：行为与改动前逐字相同
            Route::group($runtime->urlPrefix(), function () use ($routeFile): void {
                include $routeFile;
            });
        }

        /*
         * 会话中间件。
         *
         * ⚠️ 登录态依赖它。TP 默认**不开**会话（没有 app/middleware.php 时
         * SessionInit 不在全局中间件里），此时 `Session::set()` 不报错、
         * 但下一次请求读不到 —— 表现为「登录成功却立刻又跳回登录页」。
         * 实测踩到过，而且它不抛异常，只体现为一次多余的跳转。
         *
         * 这里代用户挂上：框架要用会话，就不该让用户去猜要配什么。
         * 已经在全局中间件里时不重复添加。
         */
        if (! $this->hasGlobalMiddleware(SessionInit::class)) {
            $this->app->middleware->add(SessionInit::class);
        }

        // 控制台命令 —— TP 的 Service::commands() 会在 console 启动时挂上
        $this->commands([
            InstallCommand::class,
            VerifyCommand::class,
            SchemaCommand::class,
            PermissionCommand::class,
        ]);
    }

    public function register(): void
    {
        Drivers::registerIntrospector(new ThinkIntrospector);
        Drivers::registerDictionary(new ThinkDictionary);
        Drivers::registerPermissionChecker(new ThinkPermissionChecker);
        Drivers::registerUploadSpec(new ThinkUploadSpec);

        /*
         * 多应用解析器。
         *
         * ⚠️ 与 Laravel 侧同样**不能漏** —— 漏了会回退 SingleApplication，
         * 于是菜单不过滤、权限 slug 不加前缀，且**不报错**，
         * 只是多应用静默失效（Laravel 侧就踩过这个）。
         */
        Drivers::registerApplicationResolver(new ThinkApplication);

        // 公开入口 Aimanong\Aimanong 在 core，它需要的框架设施接在这里
        Aimanong::bindRuntime(new ThinkRuntime);
    }

    /**
     * 全局中间件里是否已经有它。
     */
    protected function hasGlobalMiddleware(string $class): bool
    {
        /*
         * ⚠️ `think\Middleware` **没有** `getGlobal()` 方法 ——
         * （PHPStan 在给本包加静态分析时抓到的）。
         * 第一版直接调它 → 抛异常 → 被 catch 吞掉 → 永远返回 false →
         * 每次 boot 都重复添加 SessionInit。
         *
         * TP 的全局中间件配置在 `app/middleware.php`（由应用加载）。
         * 这里改读应用的中间件文件，读不到就当没有（重复添加无害，
         * 只是多一次空转）。
         */
        try {
            $file = $this->app->getAppPath().'middleware.php';

            if (! is_file($file)) {
                return false;
            }

            /** @var mixed $list */
            $list = include $file;

            if (! is_array($list)) {
                return false;
            }

            foreach ($list as $m) {
                if ($m === $class || (is_object($m) && $m::class === $class)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
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

        /*
         * 多应用解析器。
         *
         * ⚠️ 与 Laravel 侧同样**不能漏** —— 漏了会回退 SingleApplication，
         * 于是菜单不过滤、权限 slug 不加前缀，且**不报错**，
         * 只是多应用静默失效（Laravel 侧就踩过这个）。
         */
        Drivers::registerApplicationResolver(new ThinkApplication);
        Aimanong::bindRuntime(new ThinkRuntime);
    }
}
