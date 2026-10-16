<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Services\Exporter;
use PHPUnit\Framework\TestCase;

/**
 * 表格单元格格式化在**前端与后端之间必须一致**。
 *
 * ## 为什么必须有这个对拍
 *
 * 2026-10-17 接通 `->money('¥')` / `->decimals(n)` / `->dateTime('格式')` 时，
 * 这三处都是「列表页一套、导出 CSV 另一套」：
 *
 * | 声明 | 修之前 | 后果 |
 * |---|---|---|
 * | `->money('¥')` | 导出读 symbol，列表不读 | 同一字段 CSV 有 ¥、界面没有 |
 * | `->decimals(4)` | 两边都写死 2 位 | "恰好一致"，改一边就分叉 |
 * | `->dateTime('Y-m-d')` | 两边都写死完整格式 | "恰好一致"，同上 |
 *
 * **"恰好一致"不是一致。** 一旦只改一侧，就会出现那种
 * 「只有把界面和导出的值并排看才发现」的缺陷 —— 而这种缺陷
 * 在各自的单测里全都是绿的。
 *
 * 所以这里对**两个实现**喂同一批输入、比同一批输出。
 *
 * ## 前端的实现怎么被验到
 *
 * 前端是零构建的内联 Vue，没法在 PHPUnit 里直接跑。
 * 折中：把模板里的两个函数体**读出来**，用 node 跑一遍，
 * 与 PHP 侧的结果逐条比对。这样至少能保证：
 *   - 两侧的**记号集合**一致（不会一边支持得多）
 *   - 两侧对空值/非法值的**退化行为**一致
 *
 * node 不可用时跳过（不伪装通过）。
 */
class CellFormatParityTest extends TestCase
{
    /**
     * 日期：PHP 侧（Exporter::formatDateValue）必须与前端 formatDate 逐字一致。
     */
    public function test_date_formatting_matches_the_frontend(): void
    {
        $cases = [
            ['2026-01-05T10:20:00.000000Z', 'Y-m-d'],
            ['2026-01-05T10:20:00.000000Z', 'Y-m-d H:i:s'],
            ['2026-01-05T10:20:00.000000Z', 'Y年m月d日'],
            ['2026-01-05T10:20:00.000000Z', 'H:i'],
            ['2026-01-05T10:20:00.000000Z', 'y/n/j'],
            ['2026-01-05', 'Y-m-d'],
            ['2026-01-05', 'Y-m-d H:i:s'],
            ['not a date', 'Y-m-d'],
        ];

        $php = [];
        $ref = new \ReflectionMethod(Exporter::class, 'formatDateValue');
        $exporter = $this->exporter();

        foreach ($cases as [$v, $f]) {
            $php[] = (string) $ref->invoke($exporter, $v, $f);
        }

        $js = $this->runNode('formatDate', $cases);

        if ($js === null) {
            $this->markTestSkipped('node 不可用，跳过与前端实现的对拍');
        }

        $this->assertSame($js, $php, 'PHP 与前端（JS）的日期格式化结果必须逐字一致');
    }

    /**
     * 金额：符号 + 小数位必须一致，且**千分位只加在整数部分**。
     *
     * 回归：第一版前端实现用 `toFixed(places).replace(/\B(?=(\d{3})+(?!\d))/g, ',')`,
     * 在 `decimals(4)` 时会把小数部分也插上逗号 —— `¥1,234,567.8,910`。
     */
    public function test_money_formatting_matches_the_frontend(): void
    {
        $cases = [
            [2999, ['symbol' => '¥']],
            [1234567.891, ['symbol' => '¥', 'decimals' => 4]],
            [1234567.891, ['symbol' => '$', 'decimals' => 3]],
            [0, []],
            [12, []],
            [null, ['symbol' => '¥']],
            ['', ['symbol' => '¥']],
            ['abc', ['symbol' => '¥']],
        ];

        $php = [];

        foreach ($cases as [$v, $props]) {
            $php[] = $this->exportMoney($v, $props);
        }

        $js = $this->runNode('formatMoney', $cases);

        if ($js === null) {
            $this->markTestSkipped('node 不可用，跳过与前端实现的对拍');
        }

        $this->assertSame($js, $php, 'PHP 与前端（JS）的金额格式化结果必须逐字一致');
    }

    /**
     * 千分位不许插进小数部分 —— 直接钉住那个已修的正则。
     */
    public function test_thousands_separator_never_enters_the_decimal_part(): void
    {
        $out = $this->exportMoney(1234567.891, ['symbol' => '¥', 'decimals' => 4]);

        $this->assertSame('¥1,234,567.8910', $out);
        $this->assertStringNotContainsString('.8,910', $out, '小数部分不该有千分位逗号');
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function exportMoney(mixed $v, array $props): string
    {
        // 直接复用导出侧的实现 —— 它就是"后端那一套"
        $ref = new \ReflectionMethod(Exporter::class, 'value');

        $exporter = $this->exporter();

        return (string) $ref->invoke($exporter, ['amount' => $v], 'amount', 'money', $props);
    }

    private function exporter(): Exporter
    {
        // Exporter 无需构造依赖就能跑纯格式化分支
        $ref = new \ReflectionClass(Exporter::class);

        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * 把模板里的 JS 函数抽出来，用 node 跑同一批输入。
     *
     * @param  array<int, array{0: mixed, 1: mixed}>  $cases
     * @return array<int, string>|null node 不可用时返回 null
     */
    private function runNode(string $fn, array $cases): ?array
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            return null;
        }

        $blade = $this->viewSource();

        if (! preg_match('/function '.preg_quote($fn, '/').'\s*\(/', $blade)) {
            $this->fail("模板里找不到 {$fn}() —— 它是不是被改名/删了？");
        }

        $script = $this->extractFunctions($blade, ['formatDate', 'formatMoney']);

        $payload = json_encode($cases, JSON_UNESCAPED_UNICODE);

        /*
         * ⚠️ 两个被验函数的第二个参数含义不同：
         *   formatDate(v, col)   → col.props.format
         *   formatMoney(v, col)  → col.props.{symbol,decimals}
         * 所以包装时要分别构造，不能一律 {props: arg}。
         */
        $wrapper = $fn === 'formatDate'
            ? "{$fn}(v, {props: {format: arg}})"
            : "{$fn}(v, {props: arg})";

        $js = $script."\nconst cases = {$payload};\n"
            ."const out = cases.map(([v, arg]) => {$wrapper});\n"
            ."console.log(JSON.stringify(out));\n";

        $tmp = tempnam(sys_get_temp_dir(), 'parity').'.cjs';
        file_put_contents($tmp, $js);

        $result = shell_exec('node '.escapeshellarg($tmp).' 2>/dev/null');
        @unlink($tmp);

        if (! is_string($result) || trim($result) === '') {
            $this->fail("node 执行失败，拿不到 {$fn} 的结果（不静默通过）");
        }

        /** @var array<int, string>|null $decoded */
        $decoded = json_decode(trim($result), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function viewSource(): string
    {
        $path = dirname(__DIR__, 2).'/resources/views/resource.blade.php';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * 从模板里剪出指定的几个函数定义（按大括号配平）。
     *
     * @param  array<int, string>  $names
     */
    private function extractFunctions(string $src, array $names): string
    {
        $out = '';

        foreach ($names as $name) {
            $start = strpos($src, 'function '.$name.'(');

            if ($start === false) {
                continue;
            }

            $i = strpos($src, '{', $start);

            if ($i === false) {
                continue;
            }

            $depth = 0;

            for ($j = $i, $len = strlen($src); $j < $len; $j++) {
                if ($src[$j] === '{') {
                    $depth++;
                } elseif ($src[$j] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $out .= substr($src, $start, $j - $start + 1)."\n";

                        break;
                    }
                }
            }
        }

        return $out;
    }
}
