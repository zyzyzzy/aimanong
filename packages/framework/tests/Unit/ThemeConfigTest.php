<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ui\ThemeConfig;
use PHPUnit\Framework\TestCase;

/**
 * 界面配置测试。
 *
 * 设计要点：配置项的**声明**在 ThemeConfig（单一数据源），
 * 面板 UI / 服务端渲染 / AI 自省三处共享它。
 */
class ThemeConfigTest extends TestCase
{
    public function test_options_have_required_keys(): void
    {
        foreach (ThemeConfig::options() as $key => $opt) {
            foreach (['label', 'type', 'default'] as $k) {
                $this->assertArrayHasKey($k, $opt, "配置项 {$key} 缺少 {$k}");
            }
        }
    }

    public function test_defaults_cover_all_options(): void
    {
        $defaults = ThemeConfig::defaults();

        $this->assertSame(
            array_keys(ThemeConfig::options()),
            array_keys($defaults),
            '默认值必须覆盖全部配置项'
        );
    }

    public function test_normalize_falls_back_on_invalid_choice(): void
    {
        $out = ThemeConfig::normalize(['dark' => 'not-a-mode']);

        $this->assertSame('auto', $out['dark'], '非法深色模式应回退到默认值');
    }

    public function test_normalize_accepts_valid_choice(): void
    {
        $out = ThemeConfig::normalize(['dark' => 'dark', 'layout' => 'top']);

        $this->assertSame('dark', $out['dark']);
        $this->assertSame('top', $out['layout']);
    }

    /**
     * 回归：v3 收敛为「亮/暗双模式」，不再有 style 项。
     *
     * v2 的三套主题色相实测为 216.2° / 223.3° / 202.4° ——
     * 全是蓝色、肉眼分不出。三倍维护成本零辨识度，故移除。
     */
    public function test_no_style_option_anymore(): void
    {
        $this->assertArrayNotHasKey('style', ThemeConfig::options(),
            '风格概念已移除（三套蓝收敛为亮/暗双模式）');
    }

    /**
     * 亮/暗两套令牌必须都存在且完整。
     */
    public function test_themes_css_has_both_modes(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/assets/css/themes.css');

        $this->assertStringContainsString('[data-mode="light"]', $css);
        $this->assertStringContainsString('[data-mode="dark"]', $css);
        $this->assertStringContainsString('prefers-color-scheme: dark', $css, '需支持跟随系统');
    }

    public function test_normalize_color_validation(): void
    {
        // 合法
        $this->assertSame('#ff0000', ThemeConfig::normalize(['primary' => '#FF0000'])['primary']);
        $this->assertSame('#f00', ThemeConfig::normalize(['primary' => '#f00'])['primary']);

        // 非法 → 空（表示用风格默认色）
        foreach (['red', '#gggggg', 'javascript:alert(1)', '#12345'] as $bad) {
            $this->assertSame('', ThemeConfig::normalize(['primary' => $bad])['primary'], "应拒绝: {$bad}");
        }
    }

    public function test_normalize_switch_coerces_bool(): void
    {
        $out = ThemeConfig::normalize(['footer' => 1, 'breadcrumb' => 0]);

        $this->assertTrue($out['footer']);
        $this->assertFalse($out['breadcrumb']);
    }

    public function test_top_layout_disables_collapsed_sidebar(): void
    {
        $out = ThemeConfig::normalize(['layout' => 'top', 'sidebar_collapsed' => true]);

        $this->assertFalse($out['sidebar_collapsed'], '顶部导航下侧边栏收起无意义');
    }

    public function test_introspect_exposes_options_for_ai(): void
    {
        $info = ThemeConfig::introspect();

        $this->assertArrayHasKey('options', $info);
        $this->assertArrayHasKey('endpoints', $info);
        $this->assertCount(count(ThemeConfig::options()), $info['options']);
    }
}
