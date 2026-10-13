<?php

declare(strict_types=1);

namespace Aimanong\Console;

use Aimanong\Aimanong;
use Illuminate\Console\Command;

/**
 * 查看扩展状态。
 *
 * 扩展加载失败时，这是第一手的排查入口。
 */
class ExtensionsCommand extends Command
{
    protected $signature = 'aimanong:extensions
                            {--json : 以 JSON 输出（AI/CI 友好）}';

    protected $description = '列出已加载的扩展及其状态';

    public function handle(): int
    {
        $manager = Aimanong::extensions();
        $list = $manager->toArray();
        $isJson = (bool) $this->option('json');

        if ($isJson) {
            $this->line((string) json_encode([
                'total' => count($list),
                'failed' => count($manager->failures()),
                'extensions' => $list,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $manager->failures() === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($list === []) {
            $this->warn('未加载任何扩展。');
            $this->newLine();
            $this->line('启用方式（config/aimanong.php）：');
            $this->line("    'extensions' => [");
            $this->line('        App\\Aimanong\\Extensions\\Demo\\DemoExtension::class,');
            $this->line('    ],');
            $this->newLine();
            $this->line('生成骨架: php artisan aimanong:make-extension Demo');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($list as $ext) {
            $status = $ext['error'] !== null
                ? '<fg=red>失败</>'
                : ($ext['booted'] ? '<fg=green>已启动</>' : '<fg=yellow>未启动</>');

            $rows[] = [
                $ext['name'],
                $ext['title'],
                $ext['version'],
                $status,
                $ext['error'] ?? '',
            ];
        }

        $this->table(['标识', '名称', '版本', '状态', '错误'], $rows);

        $failed = $manager->failures();

        if ($failed !== []) {
            $this->newLine();
            $this->error(sprintf('%d 个扩展加载失败（其余扩展不受影响）', count($failed)));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('✅ 全部 %d 个扩展已启动', count($list)));

        return self::SUCCESS;
    }
}
