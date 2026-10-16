<?php

declare(strict_types=1);

namespace Aimanong\Exceptions;

/**
 * 驱动缺失。
 *
 * 声明层用到了某项需要驱动的能力（数据字典 / 权限 / 上传），
 * 但当前框架包没有注册对应驱动。
 *
 * ## 为什么必须抛而不是降级
 *
 * 这是宪法第三条（零隐式）的典型场景：
 * 声明 `->dict('order_status')` 之后静默返回空选项，
 * 界面上是一个永远选不了值的必填框 —— AI 会以为声明成功了。
 * 宁可当场报错，也不能让声明变成幽灵能力。
 */
class DriverNotRegisteredException extends AiReadableException
{
    /**
     * @param  array<int, string>  $registered
     */
    public function __construct(
        protected string $driver,
        protected string $feature,
        protected array $registered = [],
    ) {
        parent::__construct(sprintf(
            '能力 [%s] 需要 [%s] 驱动，但当前没有注册任何驱动。',
            $feature,
            $driver
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $hint = $this->registered === []
            ? '当前一个驱动都没注册 —— 通常说明框架包没被正确加载。'
            : '已注册的驱动：'.implode(', ', $this->registered);

        return [
            'driver' => $this->driver,
            'feature' => $this->feature,
            'registered_drivers' => $this->registered,
            'hint' => $hint,
            'did_you_mean' => null,
            'example' => "// Laravel 下由 AimanongServiceProvider 自动注册；\n"
                .'// 自建入口请显式调用：\\Aimanong\\Support\\Drivers::registerXxx(new YourImpl);',
            'docs' => 'https://zyzyzzy.github.io/aimanong/guide/multi-framework.html',
        ];
    }

    public static function errorCode(): string
    {
        return 'DRIVER_NOT_REGISTERED';
    }

    public function driver(): string
    {
        return $this->driver;
    }
}
