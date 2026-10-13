<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Illuminate\Console\Command;

/**
 * 生成扩展骨架。
 *
 * 降低写扩展的门槛 —— 一条命令产出可用结构。
 */
class MakeExtensionCommand extends Command
{
    protected $signature = 'aimanong:make-extension
                            {name : 扩展名（PascalCase），如 Seo}
                            {--force : 覆盖已存在的文件}';

    protected $description = '生成一个扩展骨架';

    public function handle(): int
    {
        $arg = $this->argument('name');
        $name = is_string($arg) ? $arg : '';
        $name = str_replace(['-', '_'], '', ucwords($name, '-_'));

        if ($name === '') {
            $this->error('扩展名不能为空');

            return self::FAILURE;
        }

        $kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
        $dir = app_path('Aimanong/Extensions/'.$name);

        if (is_dir($dir) && ! $this->option('force')) {
            $this->error("目录已存在: {$dir}（用 --force 覆盖）");

            return self::FAILURE;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $class = $name.'Extension';
        $file = $dir.'/'.$class.'.php';

        file_put_contents($file, $this->stub($name, $kebab, $class));

        $this->info('✅ 扩展已生成');
        $this->newLine();
        $this->line("  文件: {$file}");
        $this->newLine();
        $this->line('  启用方式（config/aimanong.php）：');
        $this->line("    'extensions' => [");
        $this->line("        \\App\\Aimanong\\Extensions\\{$name}\\{$class}::class,");
        $this->line('    ],');
        $this->newLine();
        $this->line('  验证: php artisan aimanong:extensions');

        return self::SUCCESS;
    }

    protected function stub(string $name, string $kebab, string $class): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Aimanong\\Extensions\\{$name};

        use Aimanong\\Extend\\Extension;

        /**
         * {$name} 扩展。
         */
        class {$class} extends Extension
        {
            public function name(): string
            {
                return '{$kebab}';
            }

            public function title(): string
            {
                return '{$name}';
            }

            public function version(): string
            {
                return '1.0.0';
            }

            public function description(): string
            {
                return '';
            }

            /**
             * 注册阶段：绑定容器、登记 Resource。
             *
             * 此时不要访问数据库 —— 应用可能尚未完全启动。
             */
            public function register(): void
            {
                // 例：注册本扩展提供的 Resource
                // \$this->resources(\\App\\Aimanong\\Extensions\\{$name}\\Resources\\FooResource::class);
            }

            /**
             * 启动阶段：注册路由、视图、菜单。
             */
            public function boot(): void
            {
                // 例：注册本扩展的路由（自动带后台前缀与中间件）
                // \$this->routes(function (): void {
                //     \\Illuminate\\Support\\Facades\\Route::get('/', [\\App\\...\\Controller::class, 'index']);
                // });
            }
        }

        PHP;
    }
}
