<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\OpenApiEmitter;
use PHPUnit\Framework\TestCase;

/**
 * API 契约文档测试。
 *
 * 回归：第三轮 AI 实测发现 direction 参数完全没有文档，
 * AI 只能靠枚举试出参数名 —— 猜错时不报错、静默返回未排序结果。
 */
class ApiContractTest extends TestCase
{
    /**
     * 列表接口的路径 —— 从 **emitter 的实际产出**里取，不硬编码。
     *
     * ⚠️ 这些断言原先写死 `/admin/articles`，而真实路由是 `/admin/api/articles`
     * ——「3 条断言全绿，真实路由全错」。同一个坑在 EmittersTest 里也踩过，
     * 所以这里改成从产出里找，路径怎么变都不用改测试。
     */
    /** @param array<string, mixed> $oa */
    protected function collectionPath(array $oa): string
    {
        /** @var array<string, mixed> $paths */
        $paths = is_array($oa['paths'] ?? null) ? $oa['paths'] : [];

        foreach (array_keys($paths) as $path) {
            $path = (string) $path;

            if (! str_contains($path, '{id}')) {
                return $path;
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    protected function openApi(): array
    {
        /** @var class-string<\Aimanong\Contracts\Resource> $class */
        $class = Fixtures\ArticleResource::class;
        $node = (new Compiler)->compile($class);

        return (new OpenApiEmitter)->emit($node);
    }

    public function test_list_operation_documents_direction(): void
    {
        $oa = $this->openApi();
        $params = $oa['paths'][$this->collectionPath($oa)]['get']['parameters'] ?? [];

        $names = array_column($params, 'name');

        $this->assertContains('direction', $names, '排序方向参数必须有文档，否则 AI 只能靠猜');
        $this->assertContains('keyword', $names, '搜索参数必须有文档');
        $this->assertContains('per_page', $names, '分页参数必须有文档');
        $this->assertContains('sort', $names);
    }

    public function test_direction_is_enum_documented(): void
    {
        $oa = $this->openApi();

        foreach ($oa['paths'][$this->collectionPath($oa)]['get']['parameters'] as $p) {
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

        foreach ($oa['paths'][$this->collectionPath($oa)]['get']['parameters'] as $p) {
            if ($p['name'] === 'sort') {
                // ArticleResource 只把 id 声明为 sortable
                $this->assertSame(['id'], $p['schema']['enum']);

                return;
            }
        }

        $this->fail('未找到 sort 参数');
    }
}
