<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Mcp\Tools\CreatePage;
use Aimanong\Mcp\Tools\DescribeResource;
use Aimanong\Mcp\Tools\ListResources;
use Aimanong\Mcp\Tools\QueryData;
use Aimanong\Mcp\Tools\RunVerify;
use Aimanong\Mcp\Tools\ScaffoldResource;
use Aimanong\Mcp\Tools\SearchDocs;
use Aimanong\Mcp\Tools\ValidateDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * 文档里的 MCP 工具名必须与真实注册名一致。
 *
 * ## 为什么需要这个测试
 *
 * `laravel/mcp` 的工具名是**派生**的，不是写死的：
 *
 *     Str::kebab(class_basename($this))    // ListResources → list-resources
 *
 * 于是文档作者凭直觉写成 snake_case（`list_resources`）——
 * 读起来更自然，而且**没有任何东西会报错**。
 *
 * 后果（2026-10-16 审计 A6）：
 * `llms.txt` 教 AI「**第一步总是调** `list_resources`」，
 * 而 AI 照着调会直接 not found —— 第一步就卡死。
 * 一次审计在 14 个文档里数出 103 处这样的错名。
 *
 * 这个测试把「文档写什么」和「真实叫什么」钉在一起。
 */
class McpToolNameTest extends TestCase
{
    /**
     * 真实的工具类。
     *
     * @var array<int, class-string>
     */
    private const TOOLS = [
        ListResources::class,
        DescribeResource::class,
        ScaffoldResource::class,
        CreatePage::class,
        ValidateDeclaration::class,
        RunVerify::class,
        QueryData::class,
        SearchDocs::class,
    ];

    /**
     * 真实名字必须是 kebab-case（不是 snake_case）。
     */
    public function test_real_tool_names_are_kebab_case(): void
    {
        foreach (self::TOOLS as $class) {
            $name = (new $class)->name();

            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]+(-[a-z0-9]+)*$/',
                $name,
                "{$class} 的工具名 [{$name}] 不是 kebab-case"
            );

            $this->assertStringNotContainsString(
                '_',
                $name,
                "{$class} 的工具名 [{$name}] 含下划线 —— laravel/mcp 用的是 kebab"
            );
        }
    }

    /**
     * AI 资料里出现的工具名必须真实存在。
     *
     * 扫的是**反引号包裹**的名字 —— 例如 «`list-resources`»。
     * 这样不会误伤正文里的普通英文（如 list of resources）。
     */
    public function test_documented_tool_names_are_real(): void
    {
        $real = array_map(
            static fn (string $c): string => (new $c)->name(),
            self::TOOLS
        );

        $docs = [
            'llms.txt',
            'AGENTS.md',
            'CONSTITUTION.md',
            'docs/guide/ai-collaboration.md',
            'docs/guide/ai-handbook.md',
        ];

        $root = dirname(__DIR__, 4);
        $bad = [];
        $seen = 0;

        foreach ($docs as $rel) {
            $path = $root.'/'.$rel;

            if (! is_file($path)) {
                continue;
            }

            $src = (string) file_get_contents($path);

            // 所有可能像工具名的 kebab/snake 标识符
            preg_match_all('/`([a-z][a-z0-9]*(?:[-_][a-z0-9]+)+)`/', $src, $m);

            foreach (array_unique($m[1]) as $token) {
                /*
                 * 只看「有对应的真实工具，但写法不同」的情况 ——
                 * 别的 kebab 词（如 `--with-e2e`、`pre-release`）与我们无关。
                 */
                $normalized = str_replace(['-', '_'], '', $token);

                foreach ($real as $r) {
                    if (str_replace('-', '', $r) === $normalized) {
                        $seen++;

                        if ($token !== $r) {
                            $bad[] = "{$rel}: `{$token}` 应为 `{$r}`";
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $seen,
            '一个工具名都没扫到 —— 文档结构变了？这个测试本身失效了'
        );

        $this->assertSame(
            [],
            array_values(array_unique($bad)),
            "AI 资料里的工具名与真实注册名不一致（AI 照抄会 not found）：\n  - "
            .implode("\n  - ", array_values(array_unique($bad)))
        );
    }
}
