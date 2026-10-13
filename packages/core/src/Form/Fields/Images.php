<?php

declare(strict_types=1);

namespace Aimanong\Form\Fields;

use Aimanong\Form\Concerns\HandlesUpload;

/**
 * 多图上传字段（相册、商品图集）。
 *
 * 数据库里存 JSON 数组，元素是相对路径。
 * 顺序即用户拖拽后的顺序（前端保序，服务端不重排）。
 */
class Images extends Field
{
    use HandlesUpload;

    protected string $type = 'images';

    public function __construct(string $name, ?string $label = null)
    {
        parent::__construct($name, $label);

        $this->props['multiple'] = true;
    }
}
