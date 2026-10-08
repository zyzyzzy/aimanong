<?php

declare(strict_types=1);

namespace Aimanong;

use Aimanong\Auth\AdminGuard;
use Aimanong\Auth\AdminUserProvider;
use Aimanong\Console\InstallCommand;
use Aimanong\Support\Asset;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
    ];

    /**
     * 中间件组。
     *
     * @var array<string, array<int, string>>
     */
    protected array $middlewareGroups = [
        'admin' => [
            // 必须包含 web 组的会话基础中间件，否则视图无 $errors / session
            'web',
            'admin.session',
            'admin.bootstrap',
            'admin.auth',
        ],
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aimanong.php', 'aimanong');

        $this->registerServices();
        $this->registerAuthGuard();
        $this->registerCommands();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'aimanong');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerPublishing();
        $this->registerRouteMiddleware();
        $this->bootApplication();
    }

    /**
     * 容器单例绑定。
     */
    protected function registerServices(): void
    {
        $this->app->singleton('aimanong.asset', Asset::class);
        $this->app->singleton(Registry::class, fn (): Registry => new Registry());
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
    }

    /**
     * 注册后台路由。
     */
    protected function bootApplication(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('admin')
            ->prefix($this->routePrefix())
            ->name('aimanong.')
            ->group(__DIR__.'/../routes/admin.php');
    }

    protected function routePrefix(): string
    {
        $prefix = config('aimanong.route.prefix');

        return is_string($prefix) ? trim($prefix, '/') : 'admin';
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
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
