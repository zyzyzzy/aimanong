<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

use Aimanong\Form\Concerns\HandlesUpload;

/**
 * 单文件上传字段（合同、附件、导入模板）。
 *
 * 类名是 FileField 而不是 File：`File` 容易与 Illuminate 的
 * File facade / UploadedFile 在 use 语句里打架，
 * 但**字段类型名仍然是 `file`**（AI 读的是类型名）。
 */
class FileField extends Field
{
    use HandlesUpload;

    protected string $type = 'file';

    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);

        $this->props['multiple'] = false;
    }
}
