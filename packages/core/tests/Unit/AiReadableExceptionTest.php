<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Exceptions\AiReadableException;
use Aimanong\Exceptions\DictNotFoundException;
use Aimanong\Exceptions\GhostColumnException;
use Aimanong\Exceptions\InvalidQueryColumnException;
use Aimanong\Exceptions\SortColumnNotDeclaredException;
use Aimanong\Exceptions\UnknownFieldTypeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 可自愈异常契约（宪法⑥「错误可自愈」）。
 *
 * ## 为什么这值得一个测试
 *
 * 框架的**主要使用者是 AI Agent**。异常信息里少一个 `did_you_mean`，
 * Agent 就只能靠反复试错 —— 实测里这是最贵的摩擦点。
 *
 * AGENTS.md 的「出错时怎么办」写着：
 *
 *     1. 读 did_you_mean，**改一次再试**
 *     2. 仍失败，查 /__ai/capabilities.json
 *     3. 仍失败，交给人类，**不要反复重写**
 *
 * 这套流程**依赖**异常真的带这三个字段。缺了，流程第一步就断。
 * 没有断言的话，「某个异常忘了带 did_you_mean」不会有任何机制发现。
 */
class AiReadableExceptionTest extends TestCase
{
    /**
     * @return array<string, array{0: AiReadableException}>
     */
    public static function exceptions(): array
    {
        return [
            'UnknownFieldType' => [UnknownFieldTypeException::make('txtt', 'Aimanong\\Form\\Form')],
            'GhostColumn' => [new GhostColumnException(
                '列 no_such_column 不存在',
                ghosts: ['no_such_column'],
                available: ['id', 'name'],
            )],
            'DictNotFound' => [new DictNotFoundException(
                '字典 no_such_dict 不存在',
                dictCode: 'no_such_dict',
                available: ['order_status'],
            )],
            'SortColumnNotDeclared' => [new SortColumnNotDeclaredException(
                uri: 'crm-customers',
                sort: 'name',
                sortable: ['id'],
                known: ['id', 'name'],
            )],
            'InvalidQueryColumn' => [new InvalidQueryColumnException(
                resource: 'crm-customers',
                issues: [['column' => 'bad-col', 'reason' => '原因', 'suggestion' => 'name']],
            )],
        ];
    }

    /**
     * 每个异常都必须：有错误码、能结构化输出、带 did_you_mean 或明确的替代信息。
     */
    #[DataProvider('exceptions')]
    public function test_every_exception_is_ai_readable(AiReadableException $e): void
    {
        $code = $e::errorCode();
        $this->assertNotSame('', $code, '必须有自己的错误码');
        $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', $code, '错误码应是 SCREAMING_SNAKE');

        $arr = $e->toArray();
        $this->assertSame($code, $arr['error'] ?? null, 'toArray 里的 error 必须是错误码');
        $this->assertNotSame('', (string) ($arr['message'] ?? ''), '必须有可读消息');

        // 至少要给一条「下一步怎么办」的线索：
        // did_you_mean（最可能写错的地方）或 hint/example
        $hasHint = $e->didYouMean() !== null
            || isset($arr['hint'])
            || isset($arr['example'])
            || isset($arr['available'])
            || isset($arr['suggestions']);

        $this->assertTrue($hasHint, "{$code} 没有给任何「下一步怎么办」的线索");
    }

    public function test_did_you_mean_returns_null_when_absent(): void
    {
        // 基类行为：context 里没有 did_you_mean 时返回 null，而不是空串
        $e = new class('boom') extends AiReadableException
        {
            public static function errorCode(): string
            {
                return 'TEST_ERROR';
            }

            public function context(): array
            {
                return ['hint' => '仅此而已'];
            }
        };

        $this->assertNull($e->didYouMean());
        $this->assertSame('TEST_ERROR', $e->toArray()['error']);
    }

    public function test_did_you_mean_is_read_from_context(): void
    {
        $e = new class('boom') extends AiReadableException
        {
            public static function errorCode(): string
            {
                return 'TEST_ERROR';
            }

            public function context(): array
            {
                return ['did_you_mean' => '$form->text()'];
            }
        };

        $this->assertSame('$form->text()', $e->didYouMean());
    }

    /**
     * 异常是 RuntimeException —— 驱动层可以统一捕获转成 JSON。
     */
    public function test_exceptions_are_runtime_exceptions(): void
    {
        foreach (self::exceptions() as [$e]) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }
}
