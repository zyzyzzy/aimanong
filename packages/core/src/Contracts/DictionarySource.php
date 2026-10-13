<?php

declare(strict_types=1);

namespace Aimanong\Contracts;

/**
 * 数据字典数据源契约。
 *
 * `Form::dict()` / `Column::dict()` 只依赖这三个方法，
 * 具体实现（Laravel 读表+缓存、ThinkPHP 读表）由驱动提供。
 *
 * ## 为什么必须走契约
 *
 * 声明 `->dict('order_status')` 之后，如果驱动缺失而**静默返回空选项**，
 * 界面上就是一个永远选不了值的必填框 —— AI 和人都拿不到任何反馈。
 * 所以驱动缺失时必须抛 `DriverNotRegisteredException`，不能降级。
 */
interface DictionarySource
{
    /**
     * 字典是否存在（且至少有一个启用中的条目）。
     */
    public function has(string $code): bool;

    /**
     * 全部字典的 code 列表。
     *
     * 刻意只暴露 code 而非整份描述 —— core 只需要它来生成
     * 「可用字典: a, b, c」这样的纠错提示，取最小面即可。
     *
     * @return array<int, string>
     */
    public function codes(): array;

    /**
     * 某个字典的选项列表。
     *
     * 形状必须与前端约定一致（列表页徽章、表单下拉都吃这个结构），
     * 所以契约里写死形状，而不是留成宽松的 array。
     *
     * @return array<int, array{value: string, label: string, color: string|null}>
     */
    public function options(string $code): array;
}
