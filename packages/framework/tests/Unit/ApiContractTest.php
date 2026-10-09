<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Schema\Emitters\OpenApiEmitter;
use Aimanong\Schema\Compiler;
use PHPUnit\Framework\TestCase;

/**
 * API 契约文档测试。
 *
 * 回归：第三轮 AI 实测发现 direction 参数完全没有文档，
 * AI 只能靠枚举试出参数名 —— 猜错时不报错、静默返回未排序结果。
 */
class ApiContractTest extends TestCase
{
    protected function openApi(): array
    {
        $node = (new Compiler())->compile(Fixtures\ArticleResource::class);

        return (new OpenApiEmitter())->emit($node);
    }

    public function test_list_operation_documents_direction(): void
    {
        $oa = $this->openApi();
        $params = $oa['paths']['/admin/articles']['get']['parameters'] ?? [];

        $names = array_column($params, 'name');

        $this->assertContains('direction', $names, '排序方向参数必须有文档，否则 AI 只能靠猜');
        $this->assertContains('keyword', $names, '搜索参数必须有文档');
        $this->assertContains('per_page', $names, '分页参数必须有文档');
        $this->assertContains('sort', $names);
    }

    public function test_direction_is_enum_documented(): void
    {
        $oa = $this->openApi();

        foreach ($oa['paths']['/admin/articles']['get']['parameters'] as $p) {
            if ($p['name'] === 'direction') {
                $this->assertSame(['asc', 'desc'], $p['schema']['enum']);
                $this->assertStringContainsString('不是 order', $p['description']);

                return;
            }
        }

        $this->fail('未找到 direction 参数');
    }

    public function test_sort_documents_only_sortable_columns(): void
    {
        $oa = $this->openApi();

        foreach ($oa['paths']['/admin/articles']['get']['parameters'] as $p) {
            if ($p['name'] === 'sort') {
                // ArticleResource 只把 id 声明为 sortable
                $this->assertSame(['id'], $p['schema']['enum']);

                return;
            }
        }

        $this->fail('未找到 sort 参数');
    }
}
