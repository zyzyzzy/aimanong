<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Services\Exporter;
use PHPUnit\Framework\TestCase;

/**
 * CSV 公式注入中和。
 *
 * ## 攻击
 *
 * Excel / LibreOffice / Google Sheets 会把以 `=` `+` `-` `@` 开头
 * （以及 TAB / CR 开头）的单元格**当公式求值**。
 *
 * 于是「某个字段值」就是一条攻击载荷：
 *
 *     =HYPERLINK("http://evil","点我")     诱导点击
 *     =cmd|'/c calc'!A0                    老版 Excel 的 DDE（可执行命令）
 *     [at]SUM(1+1)*cmd|'/c calc'!A0        绕过只拦 = 的实现
 *
 * 后台导出 → 管理员用 Excel 打开 CSV → 公式跑起来。
 *
 * ## 修法
 *
 * 给这类值前置一个单引号 —— Excel 的「强制文本」前缀，本身不显示。
 *
 * ⚠️ 只处理字符串：数字与布尔必须原样输出，
 * 否则负数金额会被写成 `'-100`，导出的表就没法算了。
 */
class CsvInjectionTest extends TestCase
{
    private function neutralize(mixed $v): mixed
    {
        $exporter = new Exporter;
        $m = new \ReflectionMethod($exporter, 'neutralize');
        $m->setAccessible(true);

        return $m->invoke($exporter, $v);
    }

    public function test_formula_leading_characters_are_neutralized(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)'] as $payload) {
            $this->assertSame(
                "'".$payload,
                $this->neutralize($payload),
                "以 [{$payload[0]}] 开头的值必须加文本前缀"
            );
        }
    }

    public function test_dde_payload_is_neutralized(): void
    {
        $payload = "=cmd|'/c calc'!A0";

        $this->assertStringStartsWith("'", (string) $this->neutralize($payload));
    }

    public function test_tab_and_cr_leading_are_neutralized(): void
    {
        // 有些实现只拦 =，用 TAB/CR 打头可以绕过
        $this->assertSame("'\t=1+1", $this->neutralize("\t=1+1"));
        $this->assertSame("'\r=1+1", $this->neutralize("\r=1+1"));
    }

    public function test_leading_whitespace_does_not_hide_formula(): void
    {
        // Excel 可能吃掉前导空格后露出 `=`，所以要看第一个**非空白**字符
        $this->assertSame("' =1+1", $this->neutralize(' =1+1'));
    }

    /**
     * 数字与布尔**不能**被改 —— 否则导出的表就没法算了。
     */
    public function test_numbers_and_booleans_pass_through(): void
    {
        $this->assertSame(-100, $this->neutralize(-100));
        $this->assertSame(-12.5, $this->neutralize(-12.5));
        $this->assertSame(0, $this->neutralize(0));
        $this->assertTrue($this->neutralize(true));
        $this->assertFalse($this->neutralize(false));
        $this->assertNull($this->neutralize(null));
    }

    /**
     * 普通文本不受影响（否则导出会到处多一个单引号）。
     */
    public function test_plain_text_passes_through(): void
    {
        foreach (['华东供应链', 'SO-001', 'a=b', '1+1', '', 'Hello'] as $v) {
            $this->assertSame($v, $this->neutralize($v), "[{$v}] 不该被改");
        }
    }
}
