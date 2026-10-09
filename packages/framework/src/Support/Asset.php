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
        return url(Aimanong::url('assets/'.ltrim($path, '/')));
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
