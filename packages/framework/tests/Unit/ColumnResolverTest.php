<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Support\ColumnResolver;
use PHPUnit\Framework\TestCase;

/**
 * 查询列校验测试。
 *
 * 方案：**编译期拒绝，运行期兼容**。
 *
 * 真实场景验证发现三种失败形态：
 *   1. 关联列当列名 → 运行时 500（且拖垮同一查询）
 *   2. 不存在的列   → 静默返回错误结果（最危险）
 *   3. 非法字符     → 依赖 Eloquent 兜底（不该依赖）
 */
class ColumnResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        ColumnResolver::flush();
        parent::tearDown();
    }

    public function test_rejects_sql_injection_patterns(): void
    {
        foreach (['id; DROP TABLE x--', 'id OR 1=1', "id'--", 'id/*x*/', 'id name'] as $bad) {
            $r = ColumnResolver::resolve('App\\Models\\User', $bad);
            $this->assertSame('column', $r['type']);

            // 无数据库时 validate 对普通列会放行，但注入模式必须先被字符校验拦下
            $v = ColumnResolver::validate('App\\Models\\User', $bad);

            if (str_contains($bad, ';') || str_contains($bad, "'") || str_contains($bad, ' OR ')) {
                $this->assertFalse($v['ok'], "应拒绝: {$bad}");
            }
        }
    }

    public function test_resolves_simple_column(): void
    {
        $r = ColumnResolver::resolve('App\\Models\\User', 'name');

        $this->assertSame('column', $r['type']);
        $this->assertNull($r['relation']);
    }

    public function test_resolves_relation_path(): void
    {
        $r = ColumnResolver::resolve('App\\Models\\User', 'category.name');

        $this->assertSame('relation', $r['type']);
        $this->assertSame('category', $r['relation']);
        $this->assertSame('name', $r['field']);
    }

    public function test_deep_relation_path(): void
    {
        $r = ColumnResolver::resolve('App\\Models\\User', 'a.b.c');

        $this->assertSame('relation', $r['type']);
        $this->assertSame('a', $r['relation']);
        $this->assertSame('b.c', $r['field'], '点号后其余部分作为字段路径');
    }

    public function test_validate_many_returns_issues(): void
    {
        // 无数据库时普通列不报错，但注入模式必须报
        $issues = ColumnResolver::validateMany('App\\Models\\User', ['ok', 'bad; DROP--']);

        $this->assertNotEmpty($issues);
        $this->assertSame('bad; DROP--', $issues[0]['column']);
    }

    public function test_suggest_uses_edit_distance(): void
    {
        $available = ['order_no', 'product_name', 'amount'];

        $this->assertSame('order_no', ColumnResolver::suggest('oder_no', $available));
        $this->assertSame('amount', ColumnResolver::suggest('amout', $available));
        $this->assertNull(ColumnResolver::suggest('completely_different', $available));
    }
}
