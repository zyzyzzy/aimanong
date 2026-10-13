<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

use Aimanong\Form\Concerns\HandlesUpload;

/**
 * 多文件上传字段。
 *
 * 数据库里存 JSON 数组，元素是相对路径。
 */
class Files extends Field
{
    use HandlesUpload;

    protected string $type = 'files';

    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);

        $this->props['multiple'] = true;
    }
}
