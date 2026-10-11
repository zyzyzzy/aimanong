<?php

declare(strict_types=1);

namespace Aimanong\Form\Concerns;

/**
 * 上传类字段的公共声明（image / images / file / files）。
 *
 * 这些属性最终会随 Schema 下发给前端组件，
 * 前端**不再自己判断**能不能传、传多大 —— 只负责展示。
 */
trait HandlesUpload
{
    /**
     * 允许的扩展名，如 `->accept('jpg,png')`。
     *
     * 这是**收窄**默认白名单，不是放开：
     * 服务端最终仍取「字段 accept ∩ 框架白名单」，
     * 字段里写 `php` 也不会真的允许上传 PHP。
     */
    public function accept(string $extensions): static
    {
        $this->props['accept'] = $extensions;

        return $this;
    }

    /**
     * 单文件体积上限（KB）。会与框架上限取小值。
     */
    public function maxSize(int $kilobytes): static
    {
        $this->props['maxSize'] = $kb = max(1, $kilobytes);

        return $this;
    }

    /**
     * 存到磁盘的哪个子目录（默认 `uploads`）。
     */
    public function directory(string $directory): static
    {
        $this->props['directory'] = trim($directory, '/');

        return $this;
    }

    /**
     * 覆盖存储磁盘（默认取 config 里的 foundation.upload.disk）。
     */
    public function disk(string $disk): static
    {
        $this->props['disk'] = $disk;

        return $this;
    }

    /**
     * 是否多选。单图/单文件字段为 false。
     */
    public function multiple(bool $value = true): static
    {
        $this->props['multiple'] = $value;

        return $this;
    }
}
