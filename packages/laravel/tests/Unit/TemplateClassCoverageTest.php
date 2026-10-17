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
     * 允许「模板用了但 CSS 里没有」的类 —— **例外清单，越小越好**。
     *
     * ## ⚠️ 这份清单曾经骗过我（2026-10-17 用户第二次报同一类问题）
     *
     * 第一版把 `.am-page-head__actions` 登记成
     * 「语义标记：页头操作区，布局由 .am-page-head 的 flex 负责」。
     * **那个理由是错的** —— flex 只作用于直接子元素，
     * 管不到操作区**内部**的排布。于是搜索框 / 刷新 / 新增
     * 挤成一团还互相压住，而门禁是绿的。
     *
     * 根因不在这一条豁免，而在**豁免太容易写**：
     * 只要写一句听起来合理的话，任何类都能通过。
     *
     * ## 现在两道约束
     *
     * 1. 扫描器只认真实 `class=` 属性（组件名 / id / 注释不再误报），
     *    所以例外不再需要为「假阳性」买单
     * 2. `test_exception_list_stays_small` 钉住条数上限 ——
     *    想加例外就必须先删一条，逼人真的去补 CSS
     *
     * @var array<string, string>
     */
    private const ALLOWED_MISSING = [
        /*
         * 真正的插件样式：region 的字段 UI 由插件自己的视图提供，
         * 设计系统不该假装认识它。
         */
        'am-region-field' => 'region 插件的字段 UI，样式随插件走',

        /*
         * 纯语义标记：**已逐个核对过**，样式确实全在基类上，
         * 且不存在「本该有布局却没有」的情况。
         */
        'am-upload--multiple' => '状态标记：多图布局由 .am-upload__grid 负责，该修饰符无独立规则',
        'am-table__select' => '选择列：宽度由内联 style="width:40px" 给定，无需独立规则',
    ];

    /**
     * 例外清单的条数上限。
     *
     * 上限存在的意义：例外只能**替换**不能**累加**。
     * 每次想加一条，就得先证明另一条已经不需要了 ——
     * 这会逼人回去补 CSS，而不是写一句理由放行。
     */
    private const MAX_ALLOWED_MISSING = 3;

    /**
     * 例外清单必须保持精简 —— 想加就必须先删。
     *
     * 这条存在的唯一目的：**堵住「写一句理由就放行」这条捷径**。
     * 2026-10-17 就是这么放过了 `.am-page-head__actions`，
     * 结果用户看到搜索框/刷新/新增挤成一团。
     */
    public function test_exception_list_stays_small(): void
    {
        $this->assertLessThanOrEqual(
            self::MAX_ALLOWED_MISSING,
            count(self::ALLOWED_MISSING),
            '例外清单超限了。想加一条就必须先证明另一条已经不需要 —— '
            .'例外只能替换，不能累加。'
        );
    }

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

            /*
             * ⚠️ 只从 **class 属性**里取，不扫整个文件。
             *
             * 第一版是 `preg_match_all('/\b(am-[a-z0-9_-]+)\b/', $src)` ——
             * 把三类「不是 CSS 类」的东西也当成类名了：
             *   · Vue 组件标签  `<am-cell>` / `<am-upload>`
             *   · 元素 id      `id="am-nav-toggle"`
             *   · 注释里提到的名字
             *
             * 于是产生了大量假阳性，而我的应对是往 ALLOWED_MISSING 里写理由
             * 一个个豁免 —— **豁免变成了免死金牌**。
             *
             * 后果很严重：`.am-page-head__actions` 被登记成
             * 「语义标记，布局由 .am-page-head 的 flex 负责」，
             * 而那个理由是**错的**（flex 只管直接子元素，管不到它内部）。
             * 用户于是看到「搜索框 / 刷新 / 新增 挤成一团」。
             *
             * 现在只认真实的 class 属性：`class="..."` 与 `:class="..."`。
             */
            $out += $this->classesInAttributes($src);
        }

        return $out;
    }

    /**
     * 从 `class="..."` / `:class="..."` 里取 `am-*` 类名。
     *
     * @return array<string, true>
     */
    private function classesInAttributes(string $src): array
    {
        // 先把 HTML/Blade 注释去掉 —— 注释里提到的类名不算使用
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);

        $out = [];

        /*
         * 匹配 class="..." 与 :class="..."（含单引号写法）。
         * `:class` 是动态绑定，值可能是 `{'am-x': cond}` 这种，
         * 里面的引号也会被下面的字符类覆盖到。
         */
        preg_match_all(
            '/(?::class|class)\s*=\s*(["\'])(.*?)\1/s',
            $src,
            $m,
            PREG_SET_ORDER
        );

        foreach ($m as $match) {
            preg_match_all('/\b(am-[a-z0-9_-]+)\b/', $match[2], $tokens);

            foreach ($tokens[1] as $class) {
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
