<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\RequirementChecker;
use PHPUnit\Framework\TestCase;

/**
 * 需求达标度校验测试。
 *
 * 这是对 AI 实测中暴露的核心缺陷的回归测试：
 * 原验证器只查语法合法，产出缺需求也全绿。
 */
class RequirementCheckerTest extends TestCase
{
    protected function checker(): RequirementChecker
    {
        return new RequirementChecker();
    }

    protected function fixture(): string
    {
        return Fixtures\ArticleResource::class;
    }

    public function test_all_requirements_satisfied(): void
    {
        $r = $this->checker()->check($this->fixture(), [
            'searchable' => 'title',
            'sortable' => 'id',
            'required' => 'title',
        ]);

        $this->assertTrue($r['checked']);
        $this->assertTrue($r['all_satisfied'], '全部需求应满足');
        $this->assertSame(0, $r['failed']);
    }

    public function test_missing_searchable_is_reported(): void
    {
        // body 未声明 searchable —— 必须被检出，而不是全绿
        $r = $this->checker()->check($this->fixture(), [
            'searchable' => 'title,body',
        ]);

        $this->assertFalse($r['all_satisfied'], '缺需求不应判为满足');
        $this->assertSame(1, $r['failed']);
        $this->assertStringContainsString('body', $r['results'][0]['detail']);
        $this->assertNotNull($r['results'][0]['fix'], '应给出修正代码');
    }

    public function test_missing_required_field_is_reported(): void
    {
        $r = $this->checker()->check($this->fixture(), [
            'required' => 'title,published',
        ]);

        $this->assertFalse($r['all_satisfied']);
        $this->assertStringContainsString('published', $r['message'].$r['results'][0]['detail']);
    }

    public function test_per_page_mismatch_is_reported(): void
    {
        $r = $this->checker()->check($this->fixture(), ['per_page' => 50]);

        $this->assertFalse($r['all_satisfied']);
        $this->assertStringContainsString('20', $r['results'][0]['detail']);
    }

    public function test_accepts_comma_separated_string(): void
    {
        $r = $this->checker()->check($this->fixture(), [
            'searchable' => 'title',
        ]);

        $this->assertTrue($r['all_satisfied']);
    }

    public function test_graceful_when_class_missing(): void
    {
        $r = $this->checker()->check('App\\Nope\\Missing', ['searchable' => 'x']);

        $this->assertFalse($r['checked'], '类不存在时应明确返回未核对');
    }
}
