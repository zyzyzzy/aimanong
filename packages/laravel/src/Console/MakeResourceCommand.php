<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Aimanong\Services\ResourceGenerator;
use Illuminate\Console\Command;

/**
 * 从数据库表生成 Resource。
 *
 * 与 MCP 工具 scaffold-resource 共用 ResourceGenerator ——
 * 保证两条路径产出的代码完全一致。
 *
 * 这是 M6 的「代码生成器」：一条命令从表到可访问的后台页面。
 */
class MakeResourceCommand extends Command
{
    protected $signature = 'aimanong:make-resource
                            {table : 数据库表名}
                            {--model= : Eloquent 模型全限定类名（默认按表名推导）}
                            {--label= : 中文名称（默认用表名）}
                            {--register : 生成后自动写入 ServiceProvider 注册}
                            {--force : 覆盖已存在的文件}';

    protected $description = '从数据库表生成 Resource 代码（代码生成器）';

    public function handle(): int
    {
        $tableArg = $this->argument('table');
        $table = is_string($tableArg) ? $tableArg : '';

        $modelOpt = $this->option('model');
        $labelOpt = $this->option('label');

        try {
            $generator = new ResourceGenerator;

            $result = $generator->generate(
                $table,
                is_string($modelOpt) ? $modelOpt : null,
                is_string($labelOpt) ? $labelOpt : null,
            );
        } catch (\Throwable $e) {
            $this->error('生成失败: '.$e->getMessage());
            $this->line('  提示：确认表名正确，且数据库连接可用');
            $this->line('        查看现有表: php artisan db:table');

            return self::FAILURE;
        }

        $dir = app_path('Aimanong');
        $file = $dir.'/'.$result['class'].'.php';

        if (file_exists($file) && ! $this->option('force')) {
            $this->error("文件已存在: {$file}（用 --force 覆盖）");

            return self::FAILURE;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, $result['code']);

        $this->info('✅ Resource 已生成');
        $this->newLine();
        $this->line("  文件: {$file}");
        $this->line("  字段: {$result['fields']} 个");
        $this->line("  访问: /admin/{$result['uri']}");

        // 模型缺失是最常见的坑：生成代码引用了它，但它可能不存在。
        // 前几轮 AI 实测反复指出这一点，这里主动提示（含生成命令）。
        if (! class_exists($result['model'])) {
            $this->newLine();
            $this->warn("⚠️  ️模型 {$result['model']} 不存在 —— 后台页面会报错");
            $this->line('  请先创建模型：');
            $this->line("    php artisan make:model {$result['modelBase']}");
        }

        // 自动注册
        if ($this->option('register')) {
            $this->registerToProvider($result['class']);
        } else {
            $this->newLine();
            $this->line('  下一步（任选）:');
            $this->line('    1. 加 --register 自动注册，或手动加一行到 ServiceProvider：');
            $this->line("       Aimanong::registry()->register(\\App\\Aimanong\\{$result['class']}::class);");
            $this->line('    2. 校验: php artisan ai:verify');
        }

        return self::SUCCESS;
    }

    /**
     * 把 Resource 注册到用户项目里的 ServiceProvider。
     */
    protected function registerToProvider(string $className): void
    {
        $providerFile = app_path('Providers/AimanongResourceProvider.php');

        if (! file_exists($providerFile)) {
            $this->warn('  未找到 app/Providers/AimanongResourceProvider.php，请手动注册');

            return;
        }

        $content = (string) file_get_contents($providerFile);
        $line = "        Aimanong::registry()->register(\\App\\Aimanong\\{$className}::class);";

        if (str_contains($content, $className)) {
            $this->line('  （已注册，跳过）');

            return;
        }

        // 插到 boot() 方法里最后一条 register 之后
        $updated = preg_replace(
            '/(public function boot\(\): void\s*\{)(.*?)(\n\s*\})/s',
            '$1$2'."\n".$line.'$3',
            $content,
            1
        );

        if ($updated === null || $updated === $content) {
            $this->warn('  自动注册失败（boot 方法结构不匹配），请手动添加：');
            $this->line("    {$line}");

            return;
        }

        file_put_contents($providerFile, $updated);

        $this->newLine();
        $this->info('✅ 已自动注册到 AimanongResourceProvider');
        $this->line('  校验: php artisan ai:verify');
    }
}
