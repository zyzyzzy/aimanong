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
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true
        );

        $this->assertSame(
            $composer['version'] ?? null,
            Aimanong::version(),
            'Aimanong::version() 必须与 composer.json 的 version 一致'
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
}
