<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

/**
 * 安装命令。
 *
 * Laravel 11+ 骨架变更要点：
 *   - config/app.php 不再有 providers 数组
 *   - 新增 bootstrap/providers.php
 *   - 必须改用 ServiceProvider::addProviderToBootstrapFile()
 */
class InstallCommand extends Command
{
    protected $signature = 'aimanong:install
                            {--force : 强制执行，覆盖已存在的配置}';

    protected $description = '安装 AI 码农后台框架';

    public function handle(): int
    {
        $this->info('╔══════════════════════════════════════╗');
        $this->info('║   AI 码农 · Aimanong 安装程序         ║');
        $this->info('╚══════════════════════════════════════╝');
        $this->newLine();

        $this->registerProvider();
        $this->publishAssets();
        $this->runMigrations();

        $this->newLine();
        $this->info('✅ 安装完成');
        $prefix = config('aimanong.route.prefix', 'admin');
        $this->line('   访问: /'.(is_string($prefix) ? $prefix : 'admin'));

        return self::SUCCESS;
    }

    /**
     * 注册服务提供者到 bootstrap/providers.php。
     */
    protected function registerProvider(): void
    {
        $provider = 'Aimanong\\AimanongServiceProvider';

        // Laravel 11+ 起 config/app.php 不再有 providers 数组，
        // 必须写入 bootstrap/providers.php
        ServiceProvider::addProviderToBootstrapFile($provider);
        $this->info('✓ 已注册服务提供者到 bootstrap/providers.php');
    }

    protected function publishAssets(): void
    {
        $this->callSilent('vendor:publish', [
            '--tag' => 'aimanong-config',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->info('✓ 已发布配置文件 config/aimanong.php');

        $this->callSilent('vendor:publish', [
            '--tag' => 'aimanong-migrations',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->info('✓ 已发布数据库迁移');
    }

    protected function runMigrations(): void
    {
        if (! $this->confirm('是否立即执行数据库迁移？', true)) {
            $this->line('  稍后手动执行: php artisan migrate');

            return;
        }

        Artisan::call('migrate', ['--force' => true]);
        $this->info('✓ 数据库迁移完成');
    }
}
