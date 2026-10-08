<?php

declare(strict_types=1);

namespace Aimanong\Mcp\Tools;

use Aimanong\Aimanong;
use Aimanong\Repository\EloquentRepository;
use Aimanong\Schema\Compiler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * 只读数据查询（便于 AI 调试与确认真实数据形态）。
 *
 * 安全：只读，且限制返回条数。
 */
class QueryData extends Tool
{
    protected string $description = <<<'MARKDOWN'
        只读查询某个 Resource 的数据，用于调试与确认真实数据形态。

        仅支持读取，不可写入。默认返回 5 条，最多 20 条。
    MARKDOWN;

    public function handle(Request $request): Response
    {
        $uri = (string) $request->string('uri');

        $class = Aimanong::registry()->find($uri);

        if ($class === null) {
            return Response::text(sprintf(
                "Resource [%s] 未注册。已注册: %s",
                $uri,
                implode(', ', array_keys(Aimanong::registry()->all())) ?: '（无）'
            ));
        }

        $limit = (int) ($request->integer('limit') ?: 5);
        $limit = max(1, min(20, $limit));

        try {
            $node = (new Compiler())->compile($class);
            $repo = new EloquentRepository($node->model);

            $paginator = $repo->paginate([
                'per_page' => $limit,
                // 显式转字符串：string() 返回 Stringable，
                // 仓库层的 is_string 守卫会静默忽略不可字符串化的值
                'keyword' => trim((string) $request->string('keyword')),
                'searchable' => array_values(array_map(
                    fn ($c): string => $c->name,
                    array_filter($node->columns, fn ($c): bool => $c->searchable)
                )),
            ]);

            $rows = $paginator->items();

            if ($rows === []) {
                return Response::text("表 [{$uri}] 暂无数据。");
            }

            $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return Response::text(sprintf(
                "共 %d 条，返回前 %d 条：\n\n```json\n%s\n```",
                $paginator->total(),
                count($rows),
                $json ?: '[]'
            ));
        } catch (\Throwable $e) {
            return Response::text(sprintf('查询失败: %s', $e->getMessage()));
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uri' => $schema->string()
                ->description('Resource 的 uri，如 users')
                ->required(),
            'limit' => $schema->integer()
                ->description('返回条数，1-20，默认 5'),
            'keyword' => $schema->string()
                ->description('搜索关键词（对该 Resource 的可搜索列生效）'),
        ];
    }
}
