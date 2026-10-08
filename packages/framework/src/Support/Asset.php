<?php

declare(strict_types=1);

namespace Aimanong\Support;

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
