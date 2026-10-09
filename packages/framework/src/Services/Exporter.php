<?php

declare(strict_types=1);

namespace Aimanong\Services;

use Aimanong\Contracts\Repository;
use Aimanong\Schema\Ast\ResourceNode;
use Illuminate\Support\Collection;
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

        /*
         * 导出必须套用列的展示规则 ——
         * 否则界面显示「已付款」而导出是 "paid"，与文档承诺
         * 「导出内容与界面一致」不符。
         *
         * 真实场景验证发现：运营拿导出文件汇报时，看到的是英文状态码。
         */
        $formatters = [];
        $columnProps = [];

        foreach ($node->columns as $c) {
            $formatters[$c->name] = $c->formatter;
            $columnProps[$c->name] = $c->props;
        }

        $filename ??= $node->uri.'-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($repository, $params, $exportColumns, $chunkSize, $formatters, $columnProps): void {
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
                        $name = $col['name'];

                        $row[] = $this->value(
                            $item,
                            $name,
                            $formatters[$name] ?? null,
                            $columnProps[$name] ?? []
                        );
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
     * 取一行的某个字段值，并套用列的展示规则。
     *
     * 兼容 Eloquent 模型与数组 —— 数据源可能不是 ORM。
     *
     * @param  array<string, mixed>  $props
     */
    protected function value(mixed $item, string $name, ?string $formatter = null, array $props = []): string
    {
        $v = $this->rawValue($item, $name);

        // 套用展示规则，保证导出与界面一致
        if ($formatter === 'map' || $formatter === 'enum') {
            $map = $props['map'] ?? null;

            if ($map instanceof \stdClass) {
                $map = (array) $map;
            }

            if (is_array($map)) {
                $key = is_bool($v) ? ($v ? '1' : '0') : (string) $v;

                return isset($map[$key]) ? (string) $map[$key] : (string) ($v ?? '');
            }
        }

        if ($formatter === 'bool') {
            $on = $v === true || $v === 1 || $v === '1';

            return $on
                ? (string) ($props['trueLabel'] ?? '是')
                : (string) ($props['falseLabel'] ?? '否');
        }

        if ($formatter === 'money') {
            return ($props['symbol'] ?? '').number_format((float) $v, 2, '.', '');
        }

        if ($formatter === 'datetime') {
            if ($v instanceof \DateTimeInterface) {
                return $v->format('Y-m-d H:i:s');
            }
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
            // 多对多关联：拼成「标签A / 标签B」而非 JSON（便于运营阅读）
            $flat = array_filter($v, fn ($x): bool => ! is_array($x));

            if (count($flat) === count($v)) {
                return implode(' / ', array_map(static fn ($x): string => (string) $x, $v));
            }

            return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
        }

        return (string) $v;
    }

    /**
     * 取原始值，支持关联路径（如 product.name）。
     */
    protected function rawValue(mixed $item, string $name): mixed
    {
        $parts = explode('.', $name);
        $v = $item;

        foreach ($parts as $part) {
            /*
             * 多对多关联（如 tags.name）会返回集合 ——
             * 此时不能再深取 name，而应把**每个成员的字段值**收集起来。
             *
             * 修复前：集合上取 ->name 抛
             *   "Property [name] does not exist on this collection instance"
             * 表现为导出直接 500（CMS 场景验证发现）。
             */
            // Eloquent\Collection 继承自 Support\Collection，判断一次即可
            if ($v instanceof Collection) {
                $v = $v->map(fn ($row): mixed => $this->extract($row, $part))
                    ->filter(fn ($x): bool => $x !== null && $x !== '')
                    ->values()
                    ->all();

                continue;
            }

            $v = $this->extract($v, $part);
        }

        return $v;
    }

    /**
     * 从单项（模型/数组）取属性。
     */
    protected function extract(mixed $v, string $part): mixed
    {
        if (is_array($v)) {
            return $v[$part] ?? null;
        }

        if (is_object($v)) {
            return $v->{$part} ?? null;
        }

        return null;
    }
}
