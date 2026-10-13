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
     * 框架内置资源的 URL。
     *
     * 指向包内的 resources/assets —— 通过路由提供，
     * 用户无需执行 publish 即可使用（LOGO 等）。
     */
    public function url(string $path): string
    {
        // 用 Aimanong::url() 而非直接读 config —— 后者在多应用下
        // 会被最后一次 switch() 污染（与 guard()/Session 同源的坑）
        $url = url(Aimanong::url('assets/'.ltrim($path, '/')));

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
