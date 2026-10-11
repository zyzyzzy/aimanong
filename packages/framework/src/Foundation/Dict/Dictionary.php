<?php

declare(strict_types=1);

namespace Aimanong\Foundation\Dict;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 数据字典读取器 —— 字典的**唯一**读取入口。
 *
 * ## 两种字典，一个入口
 *
 * 1. **代码声明**（优先级更高）
 *    `config('aimanong.foundation.dict.declarations')`
 *    随代码走、可 git diff、AI 能直接读到 —— 适合与业务逻辑强耦合的枚举。
 *
 * 2. **数据库字典**
 *    后台可维护 —— 适合运营要随时改文案的场景（如「状态显示名」）。
 *
 * 谁说「数据字典会引入第二个枚举数据源」？只要**读取只走这一个入口**，
 * 不构成分叉；构成分叉的是「有些地方读代码、有些地方读库」。
 *
 * ## 容错
 *
 * 本类会被 Schema 编译层在任意时刻调用（含未迁移的新项目、
 * 无 Laravel 容器的单元测试），因此：
 *   - 表不存在 → 返回空，不抛异常
 *   - 缓存不可用 → 直接查库
 * 一个字典读不到不该让整个后台打不开。
 */
class Dictionary
{
    public const CACHE_KEY = 'aimanong.dict';

    /**
     * @param  array<string, array<string, mixed>>|null  $declarations
     *                                                                  显式传入代码声明字典；为 null 时读 config。
     *                                                                  显式注入是为了可测 —— 单测环境没有 Laravel 容器，
     *                                                                  读不到 config，但字典的排序/过滤/配色逻辑仍应被覆盖。
     */
    public function __construct(protected ?array $declarations = null) {}

    /**
     * 取一个字典的全部选项（已按 sort 排序、已过滤停用项）。
     *
     * @return array<int, array{value: string, label: string, color: ?string}>
     */
    public function options(string $code): array
    {
        $items = $this->items($code);

        return array_values(array_map(
            fn (array $item): array => [
                'value' => $item['value'],
                'label' => $item['label'],
                'color' => $item['color'],
            ],
            array_filter($items, fn (array $item): bool => $item['enabled'])
        ));
    }

    /**
     * 值 → 文案 映射（给列表页 map() 用）。
     *
     * @return array<string, string>
     */
    public function map(string $code): array
    {
        $out = [];

        foreach ($this->options($code) as $option) {
            $out[(string) $option['value']] = $option['label'];
        }

        return $out;
    }

    /**
     * 值 → 语义色 映射（给徽章用）。
     *
     * @return array<string, string>
     */
    public function colors(string $code): array
    {
        $out = [];

        foreach ($this->options($code) as $option) {
            if ($option['color'] !== null && $option['color'] !== '') {
                $out[(string) $option['value']] = (string) $option['color'];
            }
        }

        return $out;
    }

    /**
     * 单个值的中文文案。
     */
    public function label(string $code, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->map($code)[(string) $value] ?? null;
    }

    /**
     * 字典是否存在（含代码声明）。
     */
    public function has(string $code): bool
    {
        return $this->items($code) !== [];
    }

    /**
     * 该字典是否由代码声明（后台不可删改）。
     */
    public function isLocked(string $code): bool
    {
        return array_key_exists($code, $this->declarations());
    }

    /**
     * 全部字典的概览 —— 供 AI 自省「项目里有哪些可选值」。
     *
     * @return array<string, array{source: string, name: string, count: int, items: array<string, string>}>
     */
    public function all(): array
    {
        $out = [];

        foreach (array_keys($this->declarations()) as $code) {
            $out[(string) $code] = $this->describe((string) $code);
        }

        foreach ($this->dbTypes() as $type) {
            $code = (string) $type['code'];

            if (! isset($out[$code])) {
                $out[$code] = $this->describe($code);
            }
        }

        return $out;
    }

    /**
     * @return array{source: string, name: string, count: int, items: array<string, string>}
     */
    protected function describe(string $code): array
    {
        $map = $this->map($code);

        return [
            'source' => $this->isLocked($code) ? 'code' : 'database',
            'name' => $this->name($code),
            'count' => count($map),
            'items' => $map,
        ];
    }

    /**
     * 字典显示名（代码声明的取声明，否则取库里的 name，再不行用 code）。
     */
    public function name(string $code): string
    {
        $declarations = $this->declarations();

        if (isset($declarations[$code]['name']) && is_string($declarations[$code]['name'])) {
            return $declarations[$code]['name'];
        }

        foreach ($this->dbTypes() as $type) {
            if ((string) $type['code'] === $code) {
                return (string) $type['name'];
            }
        }

        return $code;
    }

    /**
     * 一个字典的全部条目（含停用项，内部用）。
     *
     * @return array<int, array{value: string, label: string, color: ?string, enabled: bool, sort: int}>
     */
    public function items(string $code): array
    {
        $declared = $this->declarations()[$code] ?? null;

        if (is_array($declared)) {
            return $this->normalize($declared['items'] ?? []);
        }

        /** @var array<string, array<int, array{value: string, label: string, color: ?string, enabled: bool, sort: int}>> $all */
        $all = $this->cachedAll();

        return $all[$code] ?? [];
    }

    /**
     * 清空字典缓存。模型保存/删除时自动调用。
     */
    public static function flush(): bool
    {
        try {
            Cache::forget(self::CACHE_KEY);

            return true;
        } catch (Throwable) {
            // 无缓存驱动（单测 / 无容器）时无事可做
            return false;
        }
    }

    /**
     * 数据库字典（带缓存）。
     *
     * @return array<string, array<int, array{value: string, label: string, color: ?string, enabled: bool, sort: int}>>
     */
    protected function cachedAll(): array
    {
        try {
            /** @var array<string, array<int, array{value: string, label: string, color: ?string, enabled: bool, sort: int}>> $cached */
            $cached = Cache::remember(
                self::CACHE_KEY,
                (int) config('aimanong.foundation.dict.cache_ttl', 600),
                fn (): array => $this->fromDatabase()
            );

            return $cached;
        } catch (Throwable) {
            return $this->fromDatabase();
        }
    }

    /**
     * @return array<string, array<int, array{value: string, label: string, color: ?string, enabled: bool, sort: int}>>
     */
    protected function fromDatabase(): array
    {
        try {
            $rows = DictItem::query()
                ->orderBy('sort')
                ->orderBy('id')
                ->get();

            $out = [];

            foreach ($rows as $row) {
                $out[$row->type_code][] = [
                    'value' => (string) $row->value,
                    'label' => (string) $row->label,
                    'color' => $row->color === null ? null : (string) $row->color,
                    'enabled' => (bool) $row->enabled,
                    'sort' => (int) $row->sort,
                ];
            }

            return $out;
        } catch (Throwable) {
            // 表还没建（新项目未 migrate）→ 视为空字典
            return [];
        }
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    protected function dbTypes(): array
    {
        try {
            $out = [];

            foreach (DictType::query()->orderBy('sort')->orderBy('id')->get() as $type) {
                $out[] = ['code' => (string) $type->code, 'name' => (string) $type->name];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 配置里的代码声明字典。
     *
     * @return array<string, array<string, mixed>>
     */
    protected function declarations(): array
    {
        if ($this->declarations !== null) {
            return $this->declarations;
        }

        try {
            /** @var mixed $value */
            $value = config('aimanong.foundation.dict.declarations', []);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $value */
        return $value;
    }

    /**
     * 把 `['draft' => '草稿']` 或
     * `[['value' => 'draft', 'label' => '草稿', 'color' => 'muted']]`
     * 统一成内部结构。
     *
     * @param  array<array-key, mixed>  $items
     * @return array<int, array{value: string, label: string, color: ?string, enabled: bool, sort: int}>
     */
    protected function normalize(array $items): array
    {
        $out = [];
        $i = 0;

        foreach ($items as $key => $value) {
            $i += 10;

            if (is_array($value)) {
                $v = $value['value'] ?? $key;
                $label = $value['label'] ?? $v;
                $out[] = [
                    'value' => (string) $v,
                    'label' => (string) $label,
                    'color' => isset($value['color']) && is_string($value['color']) ? $value['color'] : null,
                    'enabled' => (bool) ($value['enabled'] ?? true),
                    'sort' => (int) ($value['sort'] ?? $i),
                ];

                continue;
            }

            $out[] = [
                'value' => (string) $key,
                'label' => is_scalar($value) ? (string) $value : (string) $key,
                'color' => null,
                'enabled' => true,
                'sort' => $i,
            ];
        }

        usort($out, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $out;
    }
}
