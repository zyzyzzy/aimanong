<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * core 自己的字符串工具。
 *
 * ## 这个类为什么存在（也是一个回归锁）
 *
 * core 原先有 5 处调用 `class_basename()` —— **Laravel 的全局助手**。
 * 一直没炸是因为 ThinkPHP 通过 `topthink/think-helper` **也**定义了它 ——
 * 「恰好两边都有」，而不是「core 自己能站住」。
 *
 * 但 `aimanong/core` 是**独立发布**的包。谁把它单独装进一个非
 * Laravel/TP 的项目，就是 `Call to undefined function class_basename()`。
 *
 * 所以：core 需要这个能力时，**自己实现**，不走框架助手。
 * `scripts/check-core-helpers.py` 是那条门禁（有反例验证）。
 */
class StrTest extends TestCase
{
    public function test_class_basename_strips_namespace(): void
    {
        $this->assertSame('User', Str::classBasename('App\\Models\\User'));
        $this->assertSame('UserResource', Str::classBasename('App\\Aimanong\\UserResource'));
        $this->assertSame('Deep', Str::classBasename('A\\B\\C\\Deep'));
    }

    /**
     * 没有命名空间时原样返回 —— 别把整个字符串切没了。
     */
    public function test_class_basename_without_namespace(): void
    {
        $this->assertSame('User', Str::classBasename('User'));
        $this->assertSame('', Str::classBasename(''));
    }

    /**
     * 边界：命名空间分隔符在开头/结尾。
     *
     * `\User`（前导反斜杠）是合法的类名写法，`class_basename` 也一样处理。
     */
    public function test_class_basename_edge_cases(): void
    {
        $this->assertSame('User', Str::classBasename('\\User'));
        $this->assertSame('', Str::classBasename('App\\Models\\'));
        $this->assertSame('Models', Str::classBasename('App\\Models'));
    }

    /**
     * 与 Laravel/TP 的 `class_basename()` 行为一致 ——
     * 两端跑同一份声明时，这里分叉会让错误信息里的类名不一致。
     */
    public function test_matches_framework_helper_semantics(): void
    {
        if (! function_exists('class_basename')) {
            $this->markTestSkipped('当前环境没有 class_basename()（core 独立测试的常态）');
        }

        foreach (['App\\Models\\User', 'User', '\\User', 'A\\B\\C'] as $class) {
            $this->assertSame(
                class_basename($class),
                Str::classBasename($class),
                "与框架助手不一致: {$class}"
            );
        }
    }
}
