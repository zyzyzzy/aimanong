<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\View;

use Aimanong\Aimanong;
use think\Template;

/**
 * ThinkPHP 视图渲染 —— 把 `aimanong::` 命名空间映射到本包的模板目录。
 *
 * ## 为什么要包一层
 *
 * Laravel 侧用 `view('aimanong::index', $data)`；TP 用 `think\Template`，
 * 两者 API 完全不同。控制器不该各写一套，所以这里提供一个统一的
 * `render()`。
 *
 * ## 模板来源
 *
 * `packages/thinkphp/resources/views/*.html` 是**生成物** ——
 * 由 `scripts/blade2think.py` 从 Laravel 那套 Blade 转出来。
 * 真源只有一个，CI 会校验两者同步（见 `scripts/check-think-templates.sh`）。
 */
final class ThinkView
{
    protected Template $template;

    public function __construct(?string $viewPath = null, ?string $cachePath = null)
    {
        $this->template = new Template([
            'view_path' => $viewPath ?? __DIR__.'/../../resources/views/',
            'cache_path' => $cachePath ?? $this->defaultCachePath(),
            'view_suffix' => 'html',
            'tpl_cache' => true,
        ]);
    }

    /**
     * 渲染模板并返回字符串。
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data = []): string
    {
        // 模板里到处都用 Aimanong::，统一在这里注入公共变量，
        // 免得每个控制器都记得传一遍（漏传就是一片空白，很难查）。
        $data['brand'] ??= Aimanong::brand();
        $data['version'] ??= Aimanong::version();
        $data['prefix'] ??= Aimanong::url('');

        ob_start();

        try {
            $this->template->fetch($template, $data);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
    }

    protected function defaultCachePath(): string
    {
        $base = function_exists('runtime_path') ? runtime_path() : sys_get_temp_dir().'/aimanong-tpl/';

        return rtrim((string) $base, '/').'/aimanong-tpl/';
    }
}
