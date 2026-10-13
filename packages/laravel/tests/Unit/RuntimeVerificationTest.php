<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Exceptions\GhostColumnException;
use Aimanong\Http\Controllers\ResourceController;
use Aimanong\Repository\EloquentRepository;
use Aimanong\Schema\Compiler;
use PHPUnit\Framework\TestCase;

/**
 * 第二轮 AI 实测暴露的缺陷的回归测试。
 *
 * 这轮的核心教训：**「声明写了」不等于「功能生效」**。
 * 校验器若只读元数据，会产生假阳性 —— 比不检查更危险。
 */
class RuntimeVerificationTest extends TestCase
{
    /**
     * perPage 必须能传进 Repository 并真正生效。
     *
     * 回归：曾出现 $grid->perPage(30) 只写进 schema.json，
     * 而 API 层永远用 20，校验器却报"已设置"。
     */
    public function test_paginate_accepts_default_per_page(): void
    {
        $ref = new \ReflectionMethod(EloquentRepository::class, 'paginate');
        $params = $ref->getParameters();

        $this->assertCount(2, $params, 'paginate() 必须接收 defaultPerPage 参数');
        $this->assertSame('defaultPerPage', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional(), 'defaultPerPage 应有默认值');
    }

    /**
     * Controller 必须把 Schema 声明的 perPage 传给 Repository。
     */
    public function test_controller_passes_declared_per_page(): void
    {
        $file = (new \ReflectionClass(ResourceController::class))->getFileName();
        $src = $file !== false ? (string) file_get_contents($file) : '';

        $this->assertStringContainsString(
            "meta['perPage']",
            $src,
            'ResourceController 必须读取 Schema 的 perPage，否则声明不生效'
        );
    }

    /**
     * 幽灵列必须被拦截 —— 原先静默通过，列凭空消失。
     */
    public function test_ghost_column_exception_is_ai_readable(): void
    {
        $e = new GhostColumnException(
            '声明了不存在的列: column:customer_nmae',
            ['column:customer_nmae'],
            ['id', 'order_no', 'customer_name']
        );

        $ctx = $e->toArray();

        $this->assertSame('GHOST_COLUMN', $e::errorCode());
        $this->assertSame('customer_name', $ctx['did_you_mean'], '应给出最接近的真实列名');
        $this->assertArrayHasKey('available_columns', $ctx);
        $this->assertContains('customer_name', $ctx['available_columns']);
    }

    /**
     * 编译期必须调用存在性检查（而非只在 Verifier 里）。
     */
    public function test_compiler_performs_existence_check(): void
    {
        $file = (new \ReflectionClass(Compiler::class))->getFileName();
        $src = $file !== false ? (string) file_get_contents($file) : '';

        $this->assertStringContainsString(
            'assertColumnsExist',
            $src,
            'Compiler 必须做幽灵列检查'
        );
    }

    /**
     * 表不可用时应跳过检查，不能把环境问题误报为声明错误。
     */
    public function test_ghost_check_skips_when_table_unavailable(): void
    {
        // Fixtures\ArticleResource 绑定的 stdClass 不是模型，应安全跳过
        $node = (new Compiler)->compile(Fixtures\ArticleResource::class);

        $this->assertNotEmpty($node->columns, '跳过检查时仍应正常产出 AST');
    }
}
