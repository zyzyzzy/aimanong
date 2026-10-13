<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 上传能力描述契约。
 *
 * 只描述「前端需要知道的上传参数」（endpoint / 体积上限 / 扩展名白名单），
 * **不包含**真正的存文件动作 —— 那是驱动自己的事。
 *
 * `JsonSchemaEmitter` 需要它来把上传能力写进四份产物，
 * 这样 AI 不必猜前端怎么传文件。
 */
interface UploadSpec
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
