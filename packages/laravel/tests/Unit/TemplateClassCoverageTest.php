<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 模板用到的 `am-*` 类，必须在设计系统 CSS 里有定义。
 *
 * ## 这条门禁是被一次真实用户反馈逼出来的
 *
 * 2026-10-17 给列表加了 `list` / `cards` 两种展示形态：模板改了，CSS 里
 * 新增了 `.am-cards` / `.am-card-item` 等规则。前端、后端、单测、e2e **全绿**。
 *
 * 然后用户发来截图：
 *
 *   > 下面的每一块都没有间距，右上角也都挤在一起了
 *
 * 真因有两层：
 *
 * 1. **CSS 与模板可能分头到达用户**：资源 URL 只带 `?v=<框架版本>`，
 *    而版本号**只在发版时变** —— 「改了 CSS 但还没发版」这段时间里，
 *    浏览器继续用缓存的旧 CSS（里面没有新类），而新模板已经在渲染新结构。
 * 2. **没有任何门禁检查「模板用到的类在 CSS 里存在吗」** ——
 *    所以「少了一条 CSS 规则」这件事，在服务端**完全不可见**。
 *
 * 第 1 条已在 `Asset::url()`（内容指纹）与资源路由（缓存头）修掉；
 * 这个测试堵第 2 条。
 *
 * ## 它防什么
 *
 * - 改模板加了新类，忘了写 CSS
 * - 改 CSS 时误删了仍在用的类
 * - 类名拼写不一致（`am-card__body` vs `am-card-item__body`）
 *
 * ## 它不防什么
 *
 * 不检查样式**好不好看**、也不检查属性级差异 —— 那些只能靠真机看
 *（AGENTS.md 第 5 条）。这里只保证「不是空的」。
 */
class TemplateClassCoverageTest extends TestCase
{
    /**
     * 允许「模板用了但 CSS 里没有」的类。
     *
     * 每一项都要写清理由。空数组是理想状态 —— 有例外就说明
     * 要么该补 CSS，要么该把这个类从模板里去掉。
     *
     * @var array<string, string>
     */
    private const ALLOWED_MISSING = [
        // 由插件字段片段自带样式，不在设计系统里
        'am-region-field' => 'region 插件的字段 UI，样式随插件走',

        /*
         * `am-cell` 是**本项目的 Vue 组件名**，不是 CSS 类。
         * 它出现在 `<am-cell ...>` 标签与说明注释里，被扫描器当成了类名。
         * 组件本身没有专属样式（单元格的视觉由 .am-detail / .am-table 等负责）。
         */
        'am-cell' => 'Vue 组件名（<am-cell>），不是 CSS 类',
        'am-upload' => 'Vue 组件名（<am-upload>），样式在 partials/upload.blade.php',
        'am-nav-toggle' => '元素 id（getElementById("am-nav-toggle")），不是 CSS 类',

        /*
         * 纯语义标记：样式全在基类上，修饰符只用来表达"这是什么"，
         * 目前没有也不需要有独立规则。留着它们是有价值的 ——
         * 将来要微调时不必改模板。
         */
        'am-input--search' => '语义标记：样式在 .am-input，搜索框只额外加了内联宽度',
        'am-upload--multiple' => '语义标记：样式在 .am-upload，多选态目前无独立规则',
        'am-page-head__actions' => '语义标记：页头操作区，布局由 .am-page-head 的 flex 负责',
        'am-table__select' => '语义标记：选择列，宽度用内联 style 给定',

        /*
         * ⚠️ `am-hl` 本身不是类 —— 真正使用的是 `.am-hl-danger` / `.am-hl-warning`
         *（见 highlightClass() 的注释：它曾经返回 `hl-danger` 而 CSS 定义的是
         * `.am-hl-danger`，颜色一直不生效）。这里出现的是**注释里的名字**。
         */
        'am-hl' => '只是注释里提到的前缀；实际类是 .am-hl-danger / .am-hl-warning',
    ];

    public function test_every_template_class_exists_in_css(): void
    {
        $used = $this->classesUsedInTemplates();
        $defined = $this->classesDefinedInCss();

        $this->assertNotEmpty($used, '没扫到任何 am- 类 —— 这个测试本身失效了');
        $this->assertNotEmpty($defined, '没扫到任何 CSS 定义 —— 路径变了？');

        $missing = [];

        // 两个集合都是 ['类名' => true] 映射 —— 要遍历**键**
        foreach (array_keys($used) as $class) {
            if (isset($defined[$class]) || isset(self::ALLOWED_MISSING[$class])) {
                continue;
            }

            $missing[] = $class;
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "以下 am- 类在模板里用到，但设计系统 CSS 里没有定义（改了模板忘了补 CSS？）：\n  - "
            .implode("\n  - ", $missing)
            ."\n\n用户看到的样子是：元素贴在一起、没有间距 —— 而服务端一切正常。"
            ."\n修法：① 在 design-system.css 补规则 ② 或把类从模板去掉"
            .' ③ 确实是插件样式就登记进 ALLOWED_MISSING'
        );
    }

    /**
     * 反向：CSS 里的 `am-` 类不该大面积无人使用。
     *
     * ⚠️ 这条**只报数不判错** —— 设计系统本来就有供使用者/插件复用的公共类，
     * 强行要求「全部被模板用到」会逼人删掉有用 API。
     * 这里只钉一个上限，防止 CSS 无限膨胀。
     */
    public function test_css_does_not_bloat_with_unused_classes(): void
    {
        $used = $this->classesUsedInTemplates();
        $defined = $this->classesDefinedInCss();

        // 两边都是 ['类名' => true] 的映射，比的是**键**
        $unused = array_diff_key($defined, $used);

        // 设计系统允许有公共类，但不能一半以上都没人用
        $ratio = count($unused) / max(1, count($defined));

        $this->assertLessThan(
            0.5,
            $ratio,
            sprintf(
                'CSS 里有 %d 个 am- 类没被任何模板使用（占 %.0f%%）—— 是不是有整块样式成了死代码？',
                count($unused),
                $ratio * 100
            )
        );
    }

    /**
     * 模板里用到的类。
     *
     * @return array<string, true>
     */
    private function classesUsedInTemplates(): array
    {
        $dir = dirname(__DIR__, 2).'/resources/views';
        $out = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // class="a b c" / :class="[...]" —— 抓所有 am- 开头的词
            preg_match_all('/\b(am-[a-z0-9_-]+)\b/', $src, $m);

            foreach ($m[1] as $class) {
                $out[$class] = true;
            }
        }

        return $out;
    }

    /**
     * 设计系统 CSS 里定义的类。
     *
     * ⚠️ 除了 core 的三个 CSS，还要扫**模板自己内联的 `<style>` 块** ——
     * 侧边栏 / 主题面板等 partial 的样式就写在各自文件里。
     * 第一版只扫 core CSS，于是把它们全报成「缺失」（假阳性）；
     * 假阳性会让这条门禁很快被忽略，那比没有更糟。
     *
     * @return array<string, true>
     */
    private function classesDefinedInCss(): array
    {
        $out = [];

        // ① core 的设计系统
        $dir = dirname(__DIR__, 3).'/core/resources/assets/css';

        foreach (['design-system.css', 'themes.css', 'user-prefs.css'] as $name) {
            $path = $dir.'/'.$name;

            if (is_file($path)) {
                $this->collectClasses((string) file_get_contents($path), $out);
            }
        }

        // ② 模板内联的 <style> 块
        $views = dirname(__DIR__, 2).'/resources/views';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $src, $m)) {
                foreach ($m[1] as $css) {
                    $this->collectClasses($css, $out);
                }
            }
        }

        return $out;
    }

    /**
     * 从一段 CSS 里收集 `.am-*` 类名。
     *
     * @param  array<string, true>  $out
     */
    private function collectClasses(string $css, array &$out): void
    {
        preg_match_all('/\.(am-[a-z0-9_-]+)/', $css, $m);

        foreach ($m[1] as $class) {
            $out[$class] = true;
        }
    }
}
