<?php

declare(strict_types=1);

namespace Aimanong\Grid\Actions;

use Aimanong\Contracts\BatchActionHandler;

/**
 * 一个自定义批量动作的声明。
 *
 * 只装**数据**（动作名、标签、处理器类名）—— 见 ADR-003。
 */
class BatchAction
{
    protected ?string $handler = null;

    public function __construct(
        protected string $name,
        protected string $label,
    ) {}

    /**
     * 指定处理器类（必须是 {@see BatchActionHandler} 的实现）。
     *
     * 这里只**记类名**，不在声明期实例化 —— 声明要能被序列化，
     * 也不能在编译时就把应用的依赖拉起来。
     */
    public function handler(string $class): static
    {
        if (! class_exists($class)) {
            throw new \InvalidArgumentException("批量动作 [{$this->name}] 的处理器类 {$class} 不存在。");
        }

        if (! is_subclass_of($class, BatchActionHandler::class)) {
            throw new \InvalidArgumentException(
                "批量动作 [{$this->name}] 的处理器 {$class} 没有实现 "
                .BatchActionHandler::class.'。它需要提供 handle(string $model, array $ids): int'
            );
        }

        $this->handler = $class;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getHandler(): ?string
    {
        return $this->handler;
    }
}
