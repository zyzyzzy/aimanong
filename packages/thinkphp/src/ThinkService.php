<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Ai\CapabilitiesRuntime;
use Aimanong\Aimanong;
use Aimanong\Extend\ExtensionManager;
use Aimanong\Extend\ExtensionRuntime;
use Aimanong\Support\Drivers;
use Aimanong\ThinkPHP\Application\ThinkApplication;
use Aimanong\ThinkPHP\Console\InstallCommand;
use Aimanong\ThinkPHP\Console\PermissionCommand;
use Aimanong\ThinkPHP\Console\SchemaCommand;
use Aimanong\ThinkPHP\Console\VerifyCommand;
use Aimanong\ThinkPHP\Drivers\ThinkRelationResolver;
use Aimanong\ThinkPHP\Http\IntrospectionController;
use think\facade\App;
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

        /*
         * ⚠️ 扩展路由必须**先于**后台路由注册。
         *
         * TP 路由是**先注册先匹配**。`route/admin.php` 末尾有一条兜底
         * `Route::get(':uri', [HomeController::class, 'resource'])` ——
         * 它会把 `/admin/extensions/demo/ping` 当成 uri=`extensions` 吞掉，
         * 于是扩展路由**注册了却永远匹配不到**，页面报
         * 「Resource [extensions] 未注册」。
         *
         * 实测踩到：路由表里明明有 `admin/extensions/demo/ping`，
         * 请求却 404。查了半天才发现是匹配顺序。
         */
        $this->bootExtensions();

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

        $this->bootAiRoutes();
    }

    /**
     * 加载并启动扩展。
     *
     * 与 Laravel 侧**同一套配置键与同一套时序**：
     * `config('aimanong.extensions')` 给类名列表 → addMany → register → boot。
     *
     * ## 在此之前 TP 侧完全没有这个机制
     *
     * 后果：`capabilities.json` 的 `extension` 段（教 AI 怎么用扩展）
     * 在 TP 上是一纸空文；装了 `aimanong/region` 的 TP 项目
     * **不会**得到 region 字段类型，而且**不报错** ——
     * 症状是 `$form->region()` 编译失败，报错却指向别处。
     *
     * `ExtensionManager` 本身已在 core（框架无关），这里只需注入平台。
     */
    protected function bootExtensions(): void
    {
        /*
         * 平台必须在**加载任何扩展之前**注册 ——
         * `Extension` 基类的 routes()/views()/translations() 走它。
         */
        ExtensionRuntime::use(new ThinkExtensionPlatform);

        $classes = config('aimanong.extensions');

        if (! is_array($classes) || $classes === []) {
            // 回退到包内默认配置（与 Laravel 侧同语义），而不是静默失效
            $default = require __DIR__.'/../config/aimanong.php';
            $classes = $default['extensions'] ?? [];
        }

        if (! is_array($classes) || $classes === []) {
            return;
        }

        $manager = new ExtensionManager;
        $manager->addMany($classes);
        $manager->register();
        $manager->boot();

    }

    /**
     * 注册 AI 自省接口 `/__ai/*`。
     *
     * ⚠️ **不套 admin 前缀、不套鉴权中间件** —— 与 Laravel 侧一致：
     * 这组接口是给 AI Agent 用的，走「仅 local/debug 或 token」那套。
     * 挂了 admin 中间件会让它变成「必须登录」，AI 用不了。
     *
     * 在此之前 TP 侧完全没有这些路由。`llms.txt` / `capabilities.json`
     * 是跨驱动的资料，教 AI「先查 /__ai/capabilities.json」——
     * TP 用户照做拿到 404，宪法①「AI 优先」在多框架下等于失效。
     */
    protected function bootAiRoutes(): void
    {
        $prefix = (string) config('aimanong.ai.route_prefix', '__ai');

        /*
         * ⚠️ `:uri` 与 `:pattern` **必须显式允许连字符**。
         *
         * TP 路由变量的默认匹配**不含 `-`**。实测踩到（2026-10-16）：
         *
         *     GET /__ai/examples/batch-handler
         *     → 404「未知的片段: batch」   ← `-handler` 被截掉了
         *
         * 这正是 `route/admin.php` 里为 `:uri` 记过的同一个坑
         *（`/api/readonly-customers` 被截成 `readonly`）。
         * 影响面不小：`batch-handler` 是 examples 的标准片段名，
         * 而 Resource 的 uri 带连字符是常态（`admin-users`、`shop-products`…）。
         *
         * 顺手也开 `completeMatch` —— 与 admin 组一致，
         * 免得「多余的段被吞」再咬一次。
         */
        $uriPattern = '[\w\-]+';

        Route::group($prefix, function () use ($uriPattern): void {
            Route::get('capabilities.json', [IntrospectionController::class, 'capabilities']);
            Route::get('openapi.json', [IntrospectionController::class, 'openapi']);
            Route::get('context', [IntrospectionController::class, 'context']);
            Route::get('menu', [IntrospectionController::class, 'menu']);
            Route::get('schema/:uri', [IntrospectionController::class, 'schema'])
                ->pattern(['uri' => $uriPattern]);
            Route::get('examples/:pattern', [IntrospectionController::class, 'examples'])
                ->pattern(['pattern' => $uriPattern]);
            Route::post('verify', [IntrospectionController::class, 'verify']);
        })->completeMatch();
    }

    public function register(): void
    {
        Drivers::registerIntrospector(new ThinkIntrospector);
        Drivers::registerRelationResolver(new ThinkRelationResolver);
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

        /*
         * 能力清单的驱动侧输入。
         *
         * `Capabilities` 本身在 core（600 多行说明文本两端完全一样），
         * 它只通过 `CapabilitiesRuntime` 取「只有驱动才知道」的那几个值。
         *
         * ⚠️ 字典这里注入的是 `ThinkDictionary::all()` —— 形状与
         * Laravel 侧 `Dictionary::all()` **逐字对齐**，否则 AI 拿到的
         * `capabilities.json` 要按驱动分支处理。
         */
        CapabilitiesRuntime::provideConfigReader(
            static fn (string $key, mixed $default = null): mixed => config($key, $default)
        );

        CapabilitiesRuntime::provideFrameworkVersion('thinkphp', App::version());

        CapabilitiesRuntime::provideDictionaries((new ThinkDictionary)->all());

        CapabilitiesRuntime::provideRbac(
            (bool) config('aimanong.auth.rbac', false)
        );
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
