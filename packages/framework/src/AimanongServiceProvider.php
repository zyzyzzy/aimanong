<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Application\ApplicationContext;
use Aimanong\Application\ApplicationManager;
use Aimanong\Auth\AdminGuard;
use Aimanong\Auth\AdminUserProvider;
use Aimanong\Console\InstallCommand;
use Aimanong\Console\PermissionCommand;
use Aimanong\Extend\ExtensionManager;
use Aimanong\Support\Asset;
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

        $changed = false;

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $current)) {
                $current[$key] = $value;
                $changed = true;
            }
        }

        if ($changed) {
            config(['aimanong' => $current]);
        }
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
        if ($this->app->routesAreCached()) {
            return;
        }

        /** @var ApplicationManager $apps */
        $apps = $this->app->make('aimanong.application');

        if ($apps->enabled()) {
            // 多应用：为每个应用挂载独立的路由前缀与 guard
            foreach ($apps->names() as $name) {
                $this->registerApplicationGuard($name, $apps);
                $this->registerApplicationRoutes($name, $apps);
            }
        } else {
            // 单后台（默认行为不变）
            Route::middleware('admin')
                ->prefix($this->routePrefix())
                ->name('aimanong.')
                ->group(__DIR__.'/../routes/admin.php');
        }

        $this->bootExtensions();
        $this->bootAiRoutes();
        $this->bootMcpServer();
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

        // 已注册则跳过（避免重复 define）
        $existing = config('auth.guards.'.$guard);

        if (is_array($existing)) {
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

        // 该应用专属的中间件组，认证走它自己的 guard
        $group = 'admin.'.$name;

        /*
         * admin.session 必须排在 web **之前**。
         *
         * web 组内部含 StartSession —— 若它先执行，
         * session 会用旧的 cookie path 启动，之后改配置无效，
         * 表现为登录态丢失 / CSRF 419 / 无限重定向。
         * 我们的 Session 中间件只改 config，不依赖 session 实例，
         * 因此可以安全地前置。
         */
        $this->app->make('router')->middlewareGroup(
            $group,
            $this->adminMiddlewareGroup('admin.auth:'.$guard)
        );

        Route::middleware($group)
            ->prefix($prefix)
            ->name('aimanong.'.$name.'.')
            ->group(function () use ($name, $apps, $title): void {
                // 进入该应用路由时切换上下文
                $apps->switch($name);

                Route::get('__app', fn (): array => [
                    'application' => $name,
                    'title' => $title,
                ])->name('info');

                require __DIR__.'/../routes/admin.php';
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
