<?php

declare(strict_types=1);

namespace Aimanong\Ui;

/**
 * 界面配置声明 —— **单一数据源**。
 *
 * ## 设计
 *
 * 所有可配置项的**声明**都在这里（键名、类型、可选值、默认值、中文标签）。
 * 三个消费者共享它：
 *   1. 配置面板 UI（渲染表单项）
 *   2. 服务端渲染（把用户偏好注入 `<html>` 属性）
 *   3. AI 自省（`/__ai/ui` 与 capabilities）
 *
 * 新增一个配置项只改这里 —— 不需要同时改前端和文档。
 *
 * ## 为什么不用数据库存"有哪些配置项"
 *
 * 那会变成"代码一份 + 数据库一份"，正是本项目禁止的分叉。
 * 数据库只存**用户选了哪个值**，不存"有哪些选项"。
 */
class ThemeConfig
{
    /**
     * 全部可配置项。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function options(): array
    {
        return [
            'style' => [
                'label' => '界面风格',
                'type' => 'style-picker',
                'default' => 'ink',
                'choices' => [
                    // 名称与 themes.css 的 data-theme 值一一对应
                    // 色值取自 LOGO 品牌色盘（主蓝 #205098 衍生）
                    'ink' => ['label' => '墨玉', 'desc' => 'LOGO 正蓝', 'swatch' => ['#3363ac', '#f2f6fd']],
                    'deep' => ['label' => '深空', 'desc' => '靛蓝沉静', 'swatch' => ['#4861a2', '#f3f6fc']],
                    'aurora' => ['label' => '极光', 'desc' => '青蓝明亮', 'swatch' => ['#006dae', '#f0f7fd']],
                ],
                'help' => '风格决定配色与质感；默认「墨玉」最耐看',
            ],

            'primary' => [
                'label' => '主题色',
                'type' => 'color',
                'default' => '',          // 空 = 用风格自带主色
                'help' => '留空则使用所选风格的默认主色',
            ],

            'dark' => [
                'label' => '深色模式',
                'type' => 'radio',
                'default' => 'auto',
                'choices' => [
                    'auto' => '跟随系统',
                    'light' => '浅色',
                    'dark' => '深色',
                ],
            ],

            'density' => [
                'label' => '界面密度',
                'type' => 'radio',
                'default' => 'comfortable',
                'choices' => [
                    'comfortable' => '舒适',
                    'compact' => '紧凑',
                ],
                'help' => '数据量大时用「紧凑」可在一屏看到更多行',
            ],

            'radius' => [
                'label' => '圆角',
                'type' => 'radio',
                'default' => 'normal',
                'choices' => [
                    'sharp' => '直角',
                    'normal' => '标准',
                    'round' => '圆润',
                ],
            ],

            'layout' => [
                'label' => '导航布局',
                'type' => 'radio',
                'default' => 'sidebar',
                'choices' => [
                    'sidebar' => '侧边导航',
                    'top' => '顶部导航',
                ],
            ],

            'sidebar_collapsed' => [
                'label' => '侧边栏默认收起',
                'type' => 'switch',
                'default' => false,
            ],

            'accordion' => [
                'label' => '手风琴菜单',
                'type' => 'switch',
                'default' => false,
                'help' => '同一时间只展开一个分组',
            ],

            'breadcrumb' => [
                'label' => '显示面包屑',
                'type' => 'switch',
                'default' => true,
            ],

            'footer' => [
                'label' => '显示页脚',
                'type' => 'switch',
                'default' => true,
            ],

            'animation' => [
                'label' => '页面切换动画',
                'type' => 'switch',
                'default' => true,
                'help' => '系统设置了「减少动效」时会自动关闭',
            ],
        ];
    }

    /**
     * 默认值集合。
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $out = [];

        foreach (self::options() as $key => $opt) {
            $out[$key] = $opt['default'];
        }

        return $out;
    }

    /**
     * 校验并规范化用户提交的偏好。
     *
     * 非法值一律回退到默认值 —— 不抛异常，避免用户改坏配置后进不去后台。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $out = self::defaults();

        foreach (self::options() as $key => $opt) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            $type = $opt['type'];

            $out[$key] = match ($type) {
                'switch' => (bool) $value,
                'radio', 'style-picker' => is_string($value) && isset($opt['choices'][$value])
                    ? $value
                    : $opt['default'],
                'color' => self::normalizeColor($value),
                default => $value,
            };
        }

        // 侧边栏默认收起只在侧边布局下有意义
        if (($out['layout'] ?? 'sidebar') === 'top') {
            $out['sidebar_collapsed'] = false;
        }

        return $out;
    }

    /**
     * 规范化颜色值。非法值返回空（表示用风格默认色）。
     */
    protected static function normalizeColor(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        // 只接受 #rgb / #rrggbb
        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? strtolower($value) : '';
    }

    /**
     * 供 AI 自省：配置项清单 + 用法。
     *
     * @return array<string, mixed>
     */
    public static function introspect(): array
    {
        $items = [];

        foreach (self::options() as $key => $opt) {
            $items[$key] = [
                'label' => $opt['label'],
                'type' => $opt['type'],
                'default' => $opt['default'],
                'choices' => $opt['choices'] ?? null,
            ];
        }

        return [
            'options' => $items,
            'storage' => 'admin_users.preferences（JSON 列），按用户保存',
            'apply' => '服务端渲染时注入 <html> 属性，无闪烁；前端改动即时预览',
            'endpoints' => [
                'GET  /{prefix}/api/ui/preferences  读取当前用户偏好',
                'POST /{prefix}/api/ui/preferences  保存偏好',
            ],
        ];
    }
}
