<?php

declare(strict_types=1);

namespace Aimanong\Support;

use Aimanong\Aimanong;

/**
 * 静态资源登记。
 *
 * 前端由 Vue 3 渲染，此处仅登记入口资源地址，
 * 不参与 jQuery/AdminLTE 时代的资源拼接逻辑。
 */
class Asset
{
    /**
     * @var array<int, string>
     */
    protected array $scripts = [];

    /**
     * @var array<int, string>
     */
    protected array $styles = [];

    public function script(string $path): static
    {
        if (! in_array($path, $this->scripts, true)) {
            $this->scripts[] = $path;
        }

        return $this;
    }

    public function style(string $path): static
    {
        if (! in_array($path, $this->styles, true)) {
            $this->styles[] = $path;
        }

        return $this;
    }

    /**
     * 内置资源在磁盘上的绝对路径。
     *
     * ## 为什么放在 core
     *
     * 这些是**框架无关的前端资源**（设计系统 CSS / Vue 运行时 / LOGO），
     * 两个驱动都要提供。放在 core 意味着**只有一份**：复制到各驱动包会
     * 变成两份要同步的真源（本项目在模板上已经吃过这个亏）。
     *
     * 各驱动的资源路由都用这个方法取路径，而不是各自拼相对路径 ——
     * 相对路径在 monorepo（`packages/<pkg>/src`）与安装后
     * （`vendor/aimanong/<pkg>/src`）两种布局下不一样，靠 `__DIR__`
     * 拼深度是脆的；这里用反射定位 core 自己的包根，两种布局都对。
     */
    public static function path(string $path = ''): string
    {
        // src/Support/Asset.php → 上溯 3 层才是包根（Support → src → 包根）
        $root = dirname((string) (new \ReflectionClass(self::class))->getFileName(), 3);

        $base = $root.'/resources/assets';

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    /**
     * 框架内置资源的 URL。
     *
     * 指向 core 的 resources/assets —— 通过驱动包的资源路由提供，
     * 用户无需执行 publish 即可使用（LOGO 等）。
     */
    public function url(string $path): string
    {
        /*
         * ⚠️ **不要** 用平台的全局 `url()` 包裹。
         *
         * `Aimanong::url()` 给出的已经是正确路径；再套一层平台 `url()`
         * 会让两端产生**不同结果**：
         *
         *   Laravel 的 url('/admin/assets/x.css') → http://host/admin/assets/x.css
         *   ThinkPHP 的 url(...)                  → /admin/assets/x.css.html  ← 多了后缀
         *
         * ThinkPHP 的 `url()` 默认带 `url_html_suffix`（html），于是所有
         * CSS/JS 请求 404 —— 后台**完全没有样式**，而页面本身是 200，
         * 不会有任何报错。实测踩到。
         */
        $url = Aimanong::url('assets/'.ltrim($path, '/'));

        /*
         * 带上版本号做缓存击穿。
         *
         * 资源路由返回的是 `Cache-Control: public`，浏览器会长期缓存 ——
         * 用户升级框架后**仍然加载旧 CSS**，表现为「改的样式没生效」，
         * 而服务端查什么都对（实测踩过：修好的高亮样式在浏览器里
         * 依旧是旧规则，排查了很久）。
         *
         * 用版本号而不是 mtime：多应用/多机部署时文件时间戳不一致。
         */
        return $url.'?v='.rawurlencode(Aimanong::version());
    }

    /**
     * @return array<int, string>
     */
    public function scripts(): array
    {
        return $this->scripts;
    }

    /**
     * @return array<int, string>
     */
    public function styles(): array
    {
        return $this->styles;
    }
}
