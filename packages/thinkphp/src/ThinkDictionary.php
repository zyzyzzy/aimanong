<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP;

use Aimanong\Contracts\DictionarySource;
use think\facade\Config;

/**
 * ThinkPHP 数据字典 —— `Contracts\DictionarySource` 实现。
 *
 * 目前只读**代码声明**（`config/aimanong.php` 的 `dict.declarations`），
 * 与 Laravel 侧的两来源（代码声明 + 数据库维护）相比少一半。
 * 这是**有意的分步**：先把契约打通、把「缺驱动必须报错」这条立起来，
 * 后台可维护的字典表留到后续。
 *
 * 声明格式与 Laravel 侧**完全一致** —— 同一份 Resource 声明在两个框架下
 * 应该得到同样的选项，这是多框架适配的意义所在。
 */
class ThinkDictionary implements DictionarySource
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function declarations(): array
    {
        try {
            /** @var mixed $value */
            $value = Config::get('aimanong.dict.declarations', []);
        } catch (\Throwable) {
            return [];
        }

        return is_array($value) ? $value : [];
    }

    public function has(string $code): bool
    {
        return $this->items($code) !== [];
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        return array_map('strval', array_keys($this->declarations()));
    }

    /**
     * @return array<int, array{value: string, label: string, color: string|null}>
     */
    public function options(string $code): array
    {
        $out = [];

        foreach ($this->items($code) as $item) {
            if (! $item['enabled']) {
                continue;
            }

            $out[] = [
                'value' => $item['value'],
                'label' => $item['label'],
                'color' => $item['color'],
            ];
        }

        return $out;
    }

    /**
     * 规范化成统一条目结构。
     *
     * 声明允许两种写法（与 Laravel 侧一致）：
     *   `'pending' => '待付款'`                                   ← 简写
     *   `['value' => 'paid', 'label' => '已付款', 'color' => …]`  ← 完整
     *
     * @return array<int, array{value: string, label: string, color: string|null, enabled: bool}>
     */
    protected function items(string $code): array
    {
        $decl = $this->declarations()[$code] ?? null;

        if (! is_array($decl)) {
            return [];
        }

        $raw = $decl['items'] ?? [];

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $out = [];

        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $v = (string) ($value['value'] ?? '');

                if ($v === '') {
                    continue;
                }

                $out[] = [
                    'value' => $v,
                    'label' => (string) ($value['label'] ?? $v),
                    'color' => isset($value['color']) ? (string) $value['color'] : null,
                    'enabled' => ($value['enabled'] ?? true) !== false,
                ];

                continue;
            }

            $out[] = [
                'value' => (string) $key,
                'label' => (string) $value,
                'color' => null,
                'enabled' => true,
            ];
        }

        return $out;
    }
}
