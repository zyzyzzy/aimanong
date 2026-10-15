<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Application\ApplicationContext;
use Aimanong\Application\ApplicationManager;
use Aimanong\Auth\AdminGuard;
use Aimanong\Auth\AdminUserProvider;
use Aimanong\Auth\PermissionGateChecker;
use Aimanong\Console\InstallCommand;
use Aimanong\Console\PermissionCommand;
use Aimanong\Drivers\Eloquent\EloquentIntrospector;
use Aimanong\Drivers\Eloquent\EloquentRelationResolver;
use Aimanong\Extend\ExtensionManager;
use Aimanong\Foundation\Dict\Dictionary;
use Aimanong\Foundation\Upload\Uploader;
use Aimanong\Support\Asset;
use Aimanong\Support\Drivers;
use Illuminate\Auth\AuthManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Registrar;

class AimanongServiceProvider extends ServiceProvider
{
    /**
     * 路由中间件别名。
     *
     * @var array<string, class-string>
     */
    protected array $routeMiddleware = [
        'admin.auth' => Http\Middleware\Authenticate::class,
        'admin.bootstrap' => Http\Middleware\Bootstrap::class,
        'admin.session' => Http\Middleware\Session::class,
        'admin.audit' => Foundation\Audit\RecordOperation::class,
    ];

    /**
     * 中间件组。
     *
     * @var array<string, array<int, string>>
     */
    /**
     * 中间件组由 adminMiddlewareGroup() 统一构造 ——
     * 见该方法上的说明（多应用模式曾漏挂审计中间件）。
     *
     * @var array<string, array<int, string>>
     */
    protected array $middlewareGroups = [];

    /**
     * 后台中间件链。
     *
     * ## 为什么抽成方法
     *
     * 单后台与多应用此前各自硬编码了一份列表，结果新增
     * `admin.audit` 时只改了单后台那份 —— 多应用模式下
     * 操作日志**一条都不会记**，而且不报错、测试全绿。
     * 两份列表 = 两次漂移机会，合并成一份。
     *
     * @param  string  $auth  认证中间件（多应用时带 guard：admin.auth:merchant）
     * @return array<int, string>
     */
    protected function adminMiddlewareGroup(string $auth = 'admin.auth'): array
    {
        return [
            // admin.session 必须在 web 之前：web 含 StartSession，
            // 若它先跑，session 会用旧 cookie path 启动，改配置就晚了。
            'admin.session',
            // web 提供会话基础（$errors / CSRF / session store）
            'web',
            'admin.bootstrap',
            $auth,
            // 审计中间件放最后：此时已认证，拿得到当前用户；
            // 且它包住整个请求，能统计耗时。
            'admin.audit',
        ];
    }

    public function register(): void
    {
        /*
         * mergeConfigFrom 会做「包内默认值 + 用户已发布配置」的浅合并。
         * 这样即使用户的 config/aimanong.php 是旧版本、缺少新增的键
         * （如 applications / extensions），也能拿到默认值 ——
         * 否则新功能会静默失效。
         */
        $this->mergeConfigFrom(__DIR__.'/../config/aimanong.php', 'aimanong');

        // 浅合并无法覆盖嵌套键，这里补齐新增的顶层键
        $this->fillMissingConfigKeys();

        $this->registerServices();
        $this->registerAuthGuard();
        $this->registerCommands();
        $this->registerDrivers();
        $this->registerRuntime();
    }

    /**
     * 绑定框架运行时 —— 公开入口 `Aimanong\Aimanong` 住在 core，
     * 它需要的那部分 Laravel 设施由这里接出去。
     *
     * ⚠️ 必须在 register() 阶段、且早于任何 `Aimanong::` 调用。
     */
    protected function registerRuntime(): void
    {
        Aimanong::bindRuntime(new LaravelRuntime);
    }

    /**
     * 注册驱动 —— core 与具体框架之间的唯一接缝。
     *
     * `packages/core` 里的 Form / Grid / Menu / Schema 只说
     * 「我需要一个 DictionarySource / PermissionChecker / UploadSpec」，
     * 具体是谁由这里决定。core 因此可以零 Illuminate 依赖，
     * ThinkPHP 包只要换成自己的实现即可。
     *
     * ⚠️ 必须在 register() 阶段完成，不能挪到 boot()：
     * 产物生成（`ai:schema`、MCP 工具）可能在 boot 之前就被调用。
     */
    protected function registerDrivers(): void
    {
        Drivers::registerIntrospector(new EloquentIntrospector);
        Drivers::registerRelationResolver(new EloquentRelationResolver);
        Drivers::registerDictionary(new Dictionary);
        Drivers::registerPermissionChecker(new PermissionGateChecker);
        Drivers::registerUploadSpec(new Uploader);

        /*
         * 多应用解析器。
         *
         * ⚠️ 这一步**不能漏** —— 漏了的话 `Drivers::application()` 会回退到
         * `SingleApplication`，于是：菜单不过滤、权限 slug 不加前缀、
         * `sync()` 只建一个应用的节点，**而这一切都不会报错**，
         * 只是多应用静默失效（第一次配三个后台时就踩到了）。
         */
        Drivers::registerApplicationResolver($this->app->make('aimanong.application'));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'aimanong');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        /*
         * 中文校验消息。
         *
         * Aimanong 是中文后台框架 —— 给用户看
         * 「The slug field is required.」既违和又降低可用性，
         * 尤其这些消息会直接显示在 AI 生成的表单上。
         *
         * ⚠️ 不传第二个参数（namespace）：传了会注册为
         * `aimanong::validation`，而 Laravel 校验器读的是默认的
         * `validation` 键，结果消息仍是英文（实测踩过）。
         * 不传则 addPath 到默认搜索路径，校验器能直接命中。
         *
         * 用户自己的 lang/zh_CN/validation.php 优先级更高，
         * 可以覆盖这里的任意条目。
         */
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang');

        $this->registerPublishing();
        $this->registerRouteMiddleware();
        $this->registerFoundationResources();
        $this->bootApplication();
    }

    /**
     * 补齐用户已发布配置中缺失的新增键。
     *
     * mergeConfigFrom 只做浅合并：用户配置里若存在 'route' 键，
     * 包内 'route' 的新子键不会被补上。这里对已知的新增顶层键做兜底。
     */
    protected function fillMissingConfigKeys(): void
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/aimanong.php';

        /** @var array<string, mixed> $current */
        $current = config('aimanong', []);

        [$current, $changed] = $this->fillMissing($current, $defaults);

        if ($changed) {
            config(['aimanong' => $current]);
        }
    }

    /**
     * 递归补齐缺失键。
     *
     * ## 为什么要递归（而不是只补顶层）
     *
     * mergeConfigFrom 是**浅合并**：用户 config 里只要有 `foundation` 这个键，
     * 包内 foundation 的其它子键（operation_log / login_log / dict）
     * 就全都拿不到默认值，新功能会静默失效 ——
     * 而且用户是在升级后才发布的配置，症状表现为
     * 「升级了但新功能没出现」，极难归因。
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $defaults
     * @return array{0: array<string, mixed>, 1: bool}
     */
    protected function fillMissing(array $current, array $defaults): array
    {
        $changed = false;

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $current)) {
                $current[$key] = $value;
                $changed = true;

                continue;
            }

            // 双方都是数组才递归；用户若显式写了标量，尊重用户
            if (is_array($value) && is_array($current[$key])) {
                /** @var array<string, mixed> $child */
                $child = $current[$key];

                [$merged, $childChanged] = $this->fillMissing($child, $value);

                if ($childChanged) {
                    $current[$key] = $merged;
                    $changed = true;
                }
            }
        }

        return [$current, $changed];
    }

    /**
     * 注册框架内置的基座 Resource。
     *
     * 这是「带地基的平台」的关键：登录日志 / 操作日志这类能力
     * 用户**一行代码都不用写**，装完就有，且能通过配置整体关掉。
     *
     * 只在配置开启时注册 —— 不注册就等于整条链路消失：
     * 菜单没有、权限节点不生成、路由 404，不会留下半开状态。
     */
    protected function registerFoundationResources(): void
    {
        $registry = Aimanong::registry();

        if (config('aimanong.foundation.operation_log.enable', true)) {
            $registry->register(Foundation\Resources\OperationLogResource::class);
        }

        if (config('aimanong.foundation.login_log.enable', true)) {
            $registry->register(Foundation\Resources\LoginLogResource::class);
        }

        if (config('aimanong.foundation.dict.enable', true)) {
            $registry->register(Foundation\Resources\DictTypeResource::class);
            $registry->register(Foundation\Resources\DictItemResource::class);
        }

        if (config('aimanong.foundation.admin_user.enable', true)) {
            $registry->register(Foundation\Resources\AdminUserResource::class);
        }
    }

    /**
     * 容器单例绑定。
     */
    protected function registerServices(): void
    {
        $this->app->singleton('aimanong.asset', Asset::class);
        $this->app->singleton('aimanong.extensions', fn (): ExtensionManager => new ExtensionManager);
        $this->app->singleton('aimanong.application', fn (): ApplicationManager => new ApplicationManager);
        $this->app->singleton('aimanong.context', fn (): ApplicationContext => new ApplicationContext);
        $this->app->singleton(Registry::class, fn (): Registry => new Registry);
    }

    /**
     * 注册 admin guard 与自定义 UserProvider。
     *
     * 注意：Laravel 11+ 起 UserProvider 契约新增 rehashPasswordIfRequired()，
     * Authenticatable 契约新增 getAuthPasswordName()，必须实现否则 fatal。
     */
    protected function registerAuthGuard(): void
    {
        /** @var array<string, mixed> $config */
        $config = config('aimanong.auth', []);
        $guardName = is_string($config['guard'] ?? null) ? $config['guard'] : 'admin';
        $providerName = is_string($config['provider'] ?? null) ? $config['provider'] : 'admin';
        $model = is_string($config['model'] ?? null)
            ? $config['model']
            : Models\Administrator::class;

        // 合并进 auth 配置，使 config('auth.guards.admin') 生效
        $this->mergeAuthConfig($guardName, $providerName, $model);

        Auth::provider('aimanong-eloquent', function ($app, array $config) {
            $model = is_string($config['model'] ?? null)
                ? $config['model']
                : Models\Administrator::class;

            return new AdminUserProvider($app['hash'], $model);
        });

        Auth::extend($guardName, function ($app, string $name, array $config) {
            $provider = Auth::createUserProvider($config['provider'] ?? null);

            if ($provider === null) {
                throw new \RuntimeException(
                    "Aimanong: 无法为 guard [{$name}] 创建 user provider，请检查 config/aimanong.php 的 auth.provider 配置。"
                );
            }

            return new AdminGuard(
                $name,
                $provider,
                $app['session.store'],
                $app['request']
            );
        });

        $this->markGuardDriverRegistered($guardName);
    }

    /**
     * 把 admin guard 配置合并进 Laravel 的 auth 配置。
     *
     * Laravel 11+ 的骨架不再包含 hashing.php，此处不依赖该配置文件。
     */
    protected function mergeAuthConfig(string $guard, string $provider, string $model): void
    {
        /** @var array<string, mixed> $auth */
        $auth = config('auth', []);

        $guards = is_array($auth['guards'] ?? null) ? $auth['guards'] : [];
        $providers = is_array($auth['providers'] ?? null) ? $auth['providers'] : [];

        $guards[$guard] = ['driver' => $guard, 'provider' => $provider];
        $providers[$provider] = ['driver' => 'aimanong-eloquent', 'model' => $model];

        $auth['guards'] = $guards;
        $auth['providers'] = $providers;

        config(['auth' => $auth]);
    }

    /**
     * 注册路由中间件。
     *
     * Router::aliasMiddleware / middlewareGroup 在 Laravel 12 上仍然可用（已实测）。
     */
    protected function registerRouteMiddleware(): void
    {
        $router = $this->app->make('router');

        foreach ($this->routeMiddleware as $key => $middleware) {
            $router->aliasMiddleware($key, $middleware);
        }

        foreach ($this->middlewareGroups as $key => $middleware) {
            $router->middlewareGroup($key, $middleware);
        }

        // 后台中间件链统一由 adminMiddlewareGroup() 提供（单一来源）
        $router->middlewareGroup('admin', $this->adminMiddlewareGroup());
    }

    /**
     * 注册后台路由。
     */
    protected function bootApplication(): void
    {
        /** @var ApplicationManager $apps */
        $apps = $this->app->make('aimanong.application');

        /*
         * ⚠️ 注册中间件组与 guard 的部分**不能**被路由缓存跳过。
         *
         * ## 回归（生产部署必踩，且报错完全指不到根因）
         *
         * 原实现是：
         *
         *     if ($this->app->routesAreCached()) { return; }   // 整段跳过
         *     ...注册中间件组 + guard...
         *
         * 而 `php artisan route:cache` 把路由里的中间件**存成组名字符串**
         * （如 `admin.admin`）。缓存加载时若没重新注册这个组，
         * 解析就失败：
         *
         *     Target class [admin.admin] does not exist  → 整个后台 500
         *
         * 实测：config:cache 单独跑没问题；加上 route:cache 立刻 500。
         * 而 Laravel 官方部署文档的第一步就是这两个命令。
         *
         * 所以拆成两段：
         *   - registerApplicationRuntime()：中间件组 + guard（**总是执行**）
         *   - 路由文件加载：仅在没有路由缓存时执行
         */
        if ($apps->enabled()) {
            foreach ($apps->names() as $name) {
                $this->registerApplicationRuntime($name, $apps);
            }
        }

        // 扩展与 AI 路由不受路由缓存影响（它们注册自己的路由）
        $this->bootExtensions();
        $this->bootAiRoutes();
        $this->bootMcpServer();

        if ($this->app->routesAreCached()) {
            return;
        }

        if ($apps->enabled()) {
            foreach ($apps->names() as $name) {
                $this->registerApplicationRoutes($name, $apps);
            }
        } else {
            // 单后台（默认行为不变）
            Route::middleware('admin')
                ->prefix($this->routePrefix())
                ->name('aimanong.')
                ->group(__DIR__.'/../routes/admin.php');
        }
    }

    /**
     * 注册某个应用的**运行时**依赖：中间件组 + guard。
     *
     * 与路由文件加载分离 —— 前者必须在路由缓存时也执行，
     * 否则缓存里的组名无法解析（见 bootApplication 的说明）。
     */
    protected function registerApplicationRuntime(string $name, ApplicationManager $apps): void
    {
        $this->registerApplicationGuard($name, $apps);

        $guard = $apps->guard($name);
        $group = 'admin.'.$name;

        $this->app->make('router')->middlewareGroup(
            $group,
            $this->adminMiddlewareGroup('admin.auth:'.$guard)
        );
    }

    /**
     * 加载并启动扩展。
     *
     * 扩展从 config('aimanong.extensions') 读取类名列表。
     * 单个扩展失败会被隔离，不影响框架本身。
     */
    protected function bootExtensions(): void
    {
        /** @var ExtensionManager $manager */
        $manager = $this->app->make('aimanong.extensions');

        /*
         * 兼容已发布过旧配置的项目：
         * 用户 config/aimanong.php 可能是旧版本，没有 extensions 键。
         * 此时回退到包内默认配置，而不是静默失效。
         */
        $classes = config('aimanong.extensions');

        if (! is_array($classes) || $classes === []) {
            $default = require __DIR__.'/../config/aimanong.php';
            $classes = $default['extensions'] ?? [];
        }

        if (is_array($classes) && $classes !== []) {
            $manager->addMany($classes);
            $manager->register();
            $manager->boot();
        }
    }

    /**
     * 注册 MCP Server（stdio 传输）。
     *
     * AI 客户端通过 `php artisan mcp:start aimanong` 启动，
     * 之后即可直接调用框架工具，无需读文档。
     */
    protected function bootMcpServer(): void
    {
        if (! class_exists(Registrar::class)) {
            return;
        }

        try {
            \Laravel\Mcp\Facades\Mcp::local('aimanong', Mcp\AimanongServer::class);
        } catch (\Throwable) {
            // MCP 未安装或版本不匹配时静默跳过，不影响框架其它功能
        }
    }

    /**
     * 注册 AI 自省接口。
     *
     * 独立于后台路由，不套 admin 中间件（AI 用 token 访问）。
     */
    protected function bootAiRoutes(): void
    {
        if (! $this->aiEnabled()) {
            return;
        }

        Route::prefix(config('aimanong.ai.route_prefix', '__ai'))
            ->name('aimanong.ai.')
            ->group(__DIR__.'/../routes/ai.php');
    }

    protected function aiEnabled(): bool
    {
        $enabled = config('aimanong.ai.enable');

        return $enabled === null
            ? ($this->app->environment('local') || (bool) config('app.debug'))
            : (bool) $enabled;
    }

    /**
     * 为单个应用注册 auth guard。
     *
     * 每个应用可以有自己的用户模型 —— 商家与运营看到的是不同的人。
     */
    protected function registerApplicationGuard(string $name, ApplicationManager $apps): void
    {
        $config = $apps->config($name) ?? [];
        $guard = $apps->guard($name);
        $auth = $config['auth'] ?? [];

        $model = is_string($auth['model'] ?? null) ? $auth['model'] : Models\Administrator::class;
        $provider = 'aimanong_'.$name;

        /*
         * ⚠️ 只有 **driver 也已注册** 时才允许跳过。
         *
         * ## 回归（生产部署必踩的坑）
         *
         * 原判断只看 `config('auth.guards.'.$guard)` 是否存在 ——
         * 而 `config:cache` 会把 guard 配置**写进缓存文件**，
         * 于是重跑时这里判定「已注册」直接 return，
         * **跳过了下面的 Auth::extend()**。
         *
         * 但 `Auth::extend()` 注册的是**运行时**的 customCreators，
         * 缓存文件里没有它。结果：
         *
         *   Auth driver [admin] for guard [admin] is not defined
         *   → 整个后台 500
         *
         * 而 Laravel 官方部署文档第一步就是 `php artisan config:cache`。
         * 实测：无缓存时 customCreators = [admin, merchant]，
         *       有缓存时只剩 [merchant]。
         */
        $existing = config('auth.guards.'.$guard);

        if (is_array($existing) && $this->guardDriverRegistered($guard)) {
            return;
        }

        $authConfig = config('auth', []);
        $authConfig['guards'][$guard] = ['driver' => $guard, 'provider' => $provider];
        $authConfig['providers'][$provider] = ['driver' => 'aimanong-eloquent', 'model' => $model];

        config(['auth' => $authConfig]);

        Auth::extend($guard, function ($app, string $n, array $c) {
            $userProvider = Auth::createUserProvider($c['provider'] ?? null);

            if ($userProvider === null) {
                throw new \RuntimeException("Aimanong: 无法为 guard [{$n}] 创建 user provider");
            }

            return new AdminGuard($n, $userProvider, $app['session.store'], $app['request']);
        });

        $this->markGuardDriverRegistered($guard);
    }

    /**
     * 本进程里**已经注册过**的 guard driver。
     *
     * ## 为什么自己记，而不是问 Laravel
     *
     * `AuthManager::customCreators` 是 protected，而 Laravel 12
     * **没有** `hasCustomCreator()` 方法 —— 我第一版正是这么写的，
     * 结果检测永远返回 false（e2e 断言当场假红）。
     *
     * 自己记是可靠的：provider 的 register() 每进程只跑一次，
     * 走到 `Auth::extend()` 就记一笔。
     *
     * @var array<string, bool>
     */
    protected static array $registeredGuardDrivers = [];

    /**
     * 某个 guard 的自定义 driver 是否已在**本进程运行时**注册。
     *
     * 配置里存在 guard 不代表 driver 可用 —— 配置可能来自
     * `config:cache` 的缓存文件，而 customCreators 是运行时状态。
     */
    protected function guardDriverRegistered(string $driver): bool
    {
        return self::$registeredGuardDrivers[$driver] ?? false;
    }

    /**
     * 记录一个已注册的 guard driver。
     */
    protected function markGuardDriverRegistered(string $driver): void
    {
        self::$registeredGuardDrivers[$driver] = true;
    }

    /**
     * 为单个应用注册路由。
     *
     * 每个应用有独立的前缀与 guard —— 请求进入时切换当前应用，
     * 使认证与配置指向正确的用户体系。
     */
    protected function registerApplicationRoutes(string $name, ApplicationManager $apps): void
    {
        $prefix = $apps->prefix($name);
        $guard = $apps->guard($name);
        $title = $apps->config($name)['title'] ?? $name;

        // 该应用专属的中间件组。它的注册在 registerApplicationRuntime()
        // 里完成 —— 那里在路由缓存时也会执行，此处不重复注册。
        $group = 'admin.'.$name;

        Route::middleware($group)
            ->prefix($prefix)
            ->name('aimanong.'.$name.'.')
            ->group(function () use ($name, $apps, $title): void {
                /*
                 * 进入该应用路由时切换上下文。
                 *
                 * ⚠️ 但这个 switch 只是为了让 `routes/admin.php` 里的
                 * **相对路由**按该应用解析（前缀/名字），它不应把
                 * 「当前应用」永久留在最后一个注册的应用上。
                 *
                 * CLI 下所有应用都会注册一遍，于是 `current` 停在最后一个 ——
                 * artisan 命令、队列任务全拿到错的后台，
                 * 权限 slug 加错前缀、菜单过滤错后台。
                 * （实测：三个应用配好后，CLI 里 current 一直是 'agent'。）
                 *
                 * 所以**注册完就恢复**：路由闭包只是「注册时执行一次」，
                 * 恢复不会影响已注册的路由。
                 */
                $previous = $apps->current();
                $apps->switch($name);

                try {
                    Route::get('__app', fn (): array => [
                        'application' => $name,
                        'title' => $title,
                    ])->name('info');

                    require __DIR__.'/../routes/admin.php';
                } finally {
                    $apps->switch($previous);
                }
            });
    }

    /**
     * 单后台模式的路由前缀。
     *
     * 仅在**未启用多应用**时使用（多应用走 registerApplicationRoutes）。
     * 此时 config 不会被 switch() 改写，直接读是安全的。
     *
     * ⚠️ 其它任何地方都不得直接读 config('aimanong.route.prefix') ——
     * 请用 Aimanong::context()->prefix()（多应用安全）。
     */
    protected function routePrefix(): string
    {
        $prefix = config('aimanong.route.prefix');

        return is_string($prefix) ? trim($prefix, '/') : 'admin';
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PermissionCommand::class,
                InstallCommand::class,
                Console\SchemaCommand::class,
                Console\VerifyCommand::class,
                Console\DocsCommand::class,
                Console\MakeExtensionCommand::class,
                Console\MakeResourceCommand::class,
                Console\ExtensionsCommand::class,
            ]);
        }
    }

    /**
     * 资源发布。
     *
     * 注意：Laravel 11+ 骨架已无 config/app.php 的 providers 数组，
     * 安装命令必须改用 ServiceProvider::addProviderToBootstrapFile()。
     */
    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/aimanong.php' => config_path('aimanong.php'),
        ], 'aimanong-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'aimanong-migrations');
    }
}
