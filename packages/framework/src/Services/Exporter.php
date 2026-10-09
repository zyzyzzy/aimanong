<?php

declare(strict_types=1);

namespace Aimanong\Services;

use Aimanong\Contracts\Repository;
use Aimanong\Schema\Ast\ResourceNode;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 数据导出。
 *
 * 设计要点：
 *   1. 复用列表页的搜索/排序/筛选逻辑 —— 导出的内容必须与用户看到的列表一致
 *   2. 分批查询 + 流式输出 —— 大表不会撑爆内存
 *   3. 导出列复用 Grid 声明 —— 不另开定义
 */
class Exporter
{
    /**
     * 导出为 CSV。
     *
     * @param  array<string, mixed>  $params  与列表接口相同的查询参数
     */
    public function csv(
        ResourceNode $node,
        Repository $repository,
        array $params = [],
        ?string $filename = null,
    ): StreamedResponse {
        $exportColumns = $node->meta['exportColumns'] ?? [];
        $chunkSize = (int) ($node->meta['exportChunkSize'] ?? 1000);

        if ($exportColumns === []) {
            // 未声明列时回退到全部列
            $exportColumns = array_map(
                fn ($c): array => ['name' => $c->name, 'label' => $c->label],
                $node->columns
            );
        }

        $filename ??= $node->uri.'-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($repository, $params, $exportColumns, $chunkSize): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // UTF-8 BOM：让 Excel 正确识别中文，否则会乱码
            fwrite($out, "\xEF\xBB\xBF");

            // 表头
            fputcsv($out, array_column($exportColumns, 'label'));

            $page = 1;

            while (true) {
                $paginator = $repository->paginate(
                    array_merge($params, ['page' => $page, 'per_page' => $chunkSize])
                );

                $items = $paginator->items();

                if ($items === []) {
                    break;
                }

                foreach ($items as $item) {
                    $row = [];

                    foreach ($exportColumns as $col) {
                        $row[] = $this->value($item, $col['name']);
                    }

                    fputcsv($out, $row);
                }

                if ($page >= $paginator->lastPage()) {
                    break;
                }

                $page++;
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * 取一行的某个字段值。
     *
     * 兼容 Eloquent 模型与数组 —— 数据源可能不是 ORM。
     */
    protected function value(mixed $item, string $name): string
    {
        if (is_array($item)) {
            $v = $item[$name] ?? null;
        } elseif (is_object($item)) {
            $v = $item->{$name} ?? null;
        } else {
            return '';
        }

        if ($v === null) {
            return '';
        }

        if (is_bool($v)) {
            return $v ? '是' : '否';
        }

        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }

        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
        }

        return (string) $v;
    }
}
