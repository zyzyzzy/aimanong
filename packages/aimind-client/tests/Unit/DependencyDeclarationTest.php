<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 「用到的外部包」必须都在 `require` 里。
 *
 * ## 这个门禁是怎么来的
 *
 * 2026-10-16 审计发现：本包的 8 个 MCP 工具 `extends Laravel\Mcp\Server\Tool`，
 * `use Laravel\Mcp\Request / Response`，而 `composer.json` 的 `require` 里
 * **只有 `illuminate/support`** —— **没有 `laravel/mcp`**。
 *
 * 后果：从 Packagist 装 `aimanong/aimind-client`，MCP server 构造时会
 * `Class "Laravel\Mcp\Server\Tool" not found`。
 *
 * 这一类 bug 在本项目里**出现过不止一次**：
 *   · `packages/thinkphp` 用了 `topthink/think-template` 但没 require
 *     （demo 的 composer.json 自己手加了 —— 于是 e2e 里它永远存在）
 *   · 本包这次
 *
 * 共同点：**demo / monorepo 里恰好有那个包**（别的包require了，
 * 或者手加了），于是「能跑」；而发布出去的用户装的是一份**缺依赖的包**。
 *
 * AGENTS.md 那条说得最准：
 * > 测试环境手动补过的东西，会掩盖包真正缺的东西。
 *
 * ## 判定方式
 *
 * 扫 `src/` 里所有 `use Xxx\Yyy;`，取顶层命名空间
 * （`Laravel` / `Illuminate` / `Symfony` …），
 * 每个都要能对应到 `require` 里的某个包名。
 */
class DependencyDeclarationTest extends TestCase
{
    /**
     * 顶层命名空间 → 提供它的 composer 包名前缀。
     *
     * 只列本包真的用到的；新增外部依赖时这里要一起改 ——
     * 忘了改会红，这正是我们想要的。
     *
     * @var array<string, string>
     */
    private const NAMESPACE_OWNERS = [
        'Laravel\\Mcp' => 'laravel/mcp',
        'Illuminate' => 'illuminate/',
        'Aimanong' => 'aimanong/',
    ];

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        $path = __DIR__.'/../../composer.json';

        return json_decode((string) file_get_contents($path), true) ?? [];
    }

    /**
     * @return array<int, string> 形如 ['Laravel\Mcp', 'Illuminate\Support', ...]
     */
    private function externalUses(): array
    {
        $src = __DIR__.'/../../src';
        $uses = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        foreach ($it as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/^use\s+([A-Za-z_][\w\\\\]*)/m', $code, $m)) {
                foreach ($m[1] as $fqn) {
                    // 只关心外部包；Aimanong\AimindClient 是自己的
                    if (str_starts_with($fqn, 'Aimanong\\AimindClient')) {
                        continue;
                    }

                    $uses[] = $fqn;
                }
            }
        }

        return array_values(array_unique($uses));
    }

    public function test_every_external_use_is_declared_in_require(): void
    {
        $require = array_keys((array) ($this->composer()['require'] ?? []));
        $undeclared = [];

        foreach ($this->externalUses() as $fqn) {
            $owner = null;

            foreach (self::NAMESPACE_OWNERS as $prefix => $pkg) {
                if (str_starts_with($fqn, $prefix)) {
                    $owner = $pkg;

                    break;
                }
            }

            if ($owner === null) {
                $undeclared[] = "{$fqn}（未在 NAMESPACE_OWNERS 里登记）";

                continue;
            }

            // 包名前缀匹配：illuminate/ → 任一 illuminate/* 都算
            $ok = false;

            foreach ($require as $declared) {
                if ($declared === $owner || (str_ends_with($owner, '/') && str_starts_with($declared, $owner))) {
                    $ok = true;

                    break;
                }
            }

            if (! $ok) {
                $undeclared[] = "{$fqn} → 需要 {$owner}，但 require 里没有";
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            "有外部类被使用但依赖没声明（从 Packagist 装会 Class not found）：\n  "
            .implode("\n  ", $undeclared)
        );
    }

    /**
     * 反向：`laravel/mcp` 必须真的在 require 里（不是只靠传递依赖）。
     *
     * 这条是**回归锁** —— 它锁的就是 2026-10-16 修掉的那个缺失。
     */
    public function test_laravel_mcp_is_a_direct_dependency(): void
    {
        $require = (array) ($this->composer()['require'] ?? []);

        $this->assertArrayHasKey(
            'laravel/mcp',
            $require,
            'MCP 工具类 extends Laravel\Mcp\Server\Tool，必须直接 require laravel/mcp'
        );
    }
}
