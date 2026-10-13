<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Contracts\UploadSpec;
use think\facade\Config;

/**
 * ThinkPHP 上传能力描述 —— `Contracts\UploadSpec` 实现。
 *
 * 只描述「前端需要知道的上传参数」，**不含**真正的存文件动作
 * （TP 侧的文件处理留到后续增量）。
 *
 * 默认值与 Laravel 侧保持一致 —— 同一份声明在两个框架下产出的
 * 产物应该可比，否则「四份产物」的跨框架意义就没了。
 */
class ThinkUploadSpec implements UploadSpec
{
    public const DEFAULT_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const DEFAULT_FILE_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

    public const DEFAULT_IMAGE_MAX_SIZE = 5120;

    public const DEFAULT_MAX_SIZE = 10240;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'urlTemplate' => (string) $this->cfg('aimanong.upload.url_template', '/uploads/{path}'),
            'endpoint' => (string) $this->cfg('aimanong.upload.endpoint', '/admin/upload'),
            'disk' => (string) $this->cfg('aimanong.upload.disk', 'public'),
            'directory' => (string) $this->cfg('aimanong.upload.directory', 'uploads'),
            'imageMaxSize' => $this->maxSize('image'),
            'fileMaxSize' => $this->maxSize('file'),
            'imageExtensions' => $this->extensions('image'),
            'fileExtensions' => $this->extensions('file'),
        ];
    }

    public function maxSize(string $kind = 'file'): int
    {
        $key = $kind === 'image' ? 'aimanong.upload.image_max_size' : 'aimanong.upload.max_size';
        $default = $kind === 'image' ? self::DEFAULT_IMAGE_MAX_SIZE : self::DEFAULT_MAX_SIZE;
        $value = $this->cfg($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array<int, string>
     */
    public function extensions(string $kind = 'file'): array
    {
        $key = $kind === 'image' ? 'aimanong.upload.image_extensions' : 'aimanong.upload.file_extensions';
        $default = $kind === 'image' ? self::DEFAULT_IMAGE_EXTENSIONS : self::DEFAULT_FILE_EXTENSIONS;
        $value = $this->cfg($key, $default);

        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_map('strval', $value));
    }

    /**
     * 读配置，读不到就用默认值。
     *
     * TP 的 Config 门面在未启动应用时会抛异常（与 Laravel 的 config() 同理），
     * 而产物生成可能在没有完整应用的环境下跑 —— 所以必须容错。
     */
    protected function cfg(string $key, mixed $default = null): mixed
    {
        try {
            return Config::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
