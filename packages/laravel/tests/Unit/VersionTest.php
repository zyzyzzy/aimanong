<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Aimanong;
use PHPUnit\Framework\TestCase;

/**
 * 版本一致性测试。
 *
 * 版本号此前散落在 4 个地方（框架类 / MCP Server / composer.json / 文档），
 * 极易改一处漏一处。现统一为 composer.json 单一来源，并用测试锁住。
 */
class VersionTest extends TestCase
{
    public function test_version_comes_from_composer_json(): void
    {
        /*
         * ⚠️ 拆包后「composer.json」有歧义：一次安装会引入三个包。
         *
         * 对外版本号是 **framework 元包**的，不是 laravel 驱动包的 ——
         * 用户装的是 framework，后台界面显示的也该是那个号码。
         * 所以这里比对 framework 的 composer.json。
         */
        $path = dirname(__DIR__, 2).'/../framework/composer.json';

        if (! is_file($path)) {
            // 只装了 laravel 驱动包时退回本包
            $path = dirname(__DIR__, 2).'/composer.json';
        }

        $composer = json_decode((string) file_get_contents($path), true);

        $this->assertSame(
            $composer['version'] ?? null,
            Aimanong::version(),
            'Aimanong::version() 必须与产品包（framework）的 composer.json 一致'
        );
    }

    public function test_version_is_semver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/i',
            Aimanong::version(),
            '版本号应遵循语义化版本'
        );
    }

    /**
     * 回归：composer.json 的描述里曾残留 "AI-First"，
     * 而项目名已统一为 Aimanong。
     */
    public function test_composer_description_uses_project_name(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true
        );

        $this->assertStringNotContainsString(
            'AI-First',
            $composer['description'] ?? '',
            'composer 描述不应再出现 AI-First（项目名是 Aimanong）'
        );
    }

    public function test_changelog_documents_current_version(): void
    {
        $changelog = dirname(__DIR__, 4).'/CHANGELOG.md';

        if (! is_file($changelog)) {
            $this->markTestSkipped('CHANGELOG.md 不存在（子包中不检查）');
        }

        $this->assertStringContainsString(
            Aimanong::version(),
            (string) file_get_contents($changelog),
            'CHANGELOG 必须记录当前版本'
        );
    }

    /**
     * 回归：MCP Server 曾把版本号写死成 `'1.4.1'` 字面量。
     *
     * 后果比看起来严重 —— AI 客户端通过 MCP 握手拿到的 serverInfo.version
     * 会与实际发布的框架版本不一致，AI 会基于错误版本判断能力是否存在。
     */
    public function test_mcp_server_version_is_not_hardcoded(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Mcp/AimanongServer.php');

        $this->assertMatchesRegularExpression(
            '/\$version\s*=\s*\'\';/',
            $source,
            'MCP Server 的版本号不应写死，应由 composer.json 注入'
        );

        $this->assertStringNotContainsString(
            "'".Aimanong::version()."'",
            $source,
            'MCP Server 源码里不应出现当前版本号字面量'
        );
    }

    /**
     * README 的版本徽章也必须跟着走 —— 它是访客看到的第一印象。
     */
    /**
     * 框架版本必须来自 framework 包，不能落到 core 的版本。
     *
     * ## 这个 bug 只在 CI 里出现过
     *
     * `Aimanong::version()` 的兜底原先写死 `__DIR__.'/../../framework'`：
     *
     *   开发机：vendor/aimanong/core 是**软链** → 指到 monorepo → 找得到 ✅
     *   CI/用户：是**真实目录**（Packagist 装的）→ 找不到
     *           → 落到 core/composer.json → 返回 **core 的版本** ❌
     *
     * 而 core(1.6.3) 与 framework(1.11.0) 版本号不同，
     * 于是「框架版本」静默变成了 core 的版本 —— 本地永远复现不了。
     *
     * 现在改为从本文件向上找 `packages/framework/composer.json`，
     * 无论 core 是软链还是拷贝都能找到。
     */
    public function test_version_resolves_framework_not_core(): void
    {
        $frameworkVersion = json_decode(
            (string) file_get_contents(__DIR__.'/../../../framework/composer.json'),
            true
        )['version'] ?? null;

        $coreVersion = json_decode(
            (string) file_get_contents(__DIR__.'/../../../core/composer.json'),
            true
        )['version'] ?? null;

        $this->assertNotNull($frameworkVersion, 'framework/composer.json 应有 version');
        $this->assertNotSame(
            $frameworkVersion,
            $coreVersion,
            '两个包版本号相同时这条测试就失去意义了（需要更新断言）'
        );

        $this->assertSame(
            $frameworkVersion,
            Aimanong::version(),
            'Aimanong::version() 必须返回 framework 的版本，不能落到 core 的版本'
        );
    }

    public function test_find_framework_version_walks_up(): void
    {
        // 从很深的子目录也能向上找到（模拟 vendor/aimanong/core/src）
        $deep = __DIR__.'/../../../core/src/Aimanong';

        $method = new \ReflectionMethod(Aimanong::class, 'findFrameworkVersion');
        $method->setAccessible(true);

        $found = $method->invoke(null, $deep);

        $this->assertNotNull($found, '从 core/src 任意深度都应能向上找到 packages/framework');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $found);
    }

    public function test_find_framework_version_returns_null_outside_repo(): void
    {
        // 不在任何 monorepo 里 → null（走兜底，不假装找到）
        $method = new \ReflectionMethod(Aimanong::class, 'findFrameworkVersion');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, sys_get_temp_dir()));
    }

    public function test_readme_badge_matches_version(): void
    {
        $readme = dirname(__DIR__, 4).'/README.md';

        if (! is_file($readme)) {
            $this->markTestSkipped('README.md 不存在（子包中不检查）');
        }

        $this->assertStringContainsString(
            'badge/version-'.Aimanong::version().'-',
            (string) file_get_contents($readme),
            'README 版本徽章必须与 composer.json 一致'
        );
    }
}
