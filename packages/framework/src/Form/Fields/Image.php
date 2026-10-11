<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

use Aimanong\Form\Concerns\HandlesUpload;

/**
 * 单图上传字段。
 *
 * ```php
 * $form->image('avatar')->label('头像')->accept('jpg,png,webp')->maxSize(2048);
 * ```
 *
 * 数据库里存的是**相对路径**（如 `uploads/2026/10/xxx.jpg`），
 * 不是完整 URL —— 换域名 / 换 CDN 时不用刷数据。
 * 展示时由 Uploader::urlFromValue() 解析（同时兼容外链）。
 */
class Image extends Field
{
    use HandlesUpload;

    protected string $type = 'image';

    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);

        $this->props['multiple'] = false;
    }
}
