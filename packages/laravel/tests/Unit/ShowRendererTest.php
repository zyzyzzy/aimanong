<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Aimanong;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Aimanong\Tests\Unit\Fixtures\FakeFrameworkRuntime;
use Aimanong\Tests\Unit\Fixtures\TabbedDetailResource;
use PHPUnit\Framework\TestCase;

/**
 * `Show`（详情页）的**渲染方**必须存在。
 *
 * ## 这个测试防的是一类具体的历史缺陷
 *
 * `Show` 类、`Resource::show()`、编译、四产物、自省接口 —— **全都在**，
 * 5 个内置 Resource（admin-users / admin-dict-types / admin-dict-items /
 * admin-operation-logs / admin-login-logs）也都写了 `show()`。
 *
 * 但**前端一次都没读过它**：`schema.show.fields` 下发了，
 * 页面上却没有详情页。属于「声明了但没有渲染方」——
 * 写了不生效，也不报错。与那 13 个死 prop 是同一族。
 *
 * 2026-10-17 补上渲染方（列表页的详情弹窗）。
 *
 * ## 为什么断言要盯「渲染方」而不只是「数据下发」
 *
 * 只断言 schema 里有 show.fields，这个缺陷**照样能通过** ——
 * 它当初就是那样的。必须断言前端真的有消费代码。
 */
class ShowRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Aimanong::bindRuntime(new FakeFrameworkRuntime);
    }

    /**
     * show() 声明的字段要能到 schema（这一条本来就有，作为基线）。
     */
    public function test_declared_detail_fields_reach_the_schema(): void
    {
        $node = (new Compiler)->compile(TabbedDetailResource::class);
        $schema = (new JsonSchemaEmitter)->emit($node);

        $names = array_column($schema['show']['fields'], 'name');

        $this->assertSame(['id', 'name', 'phone', 'created_at'], $names);
    }

    /**
     * 🔴 前端必须真的读 `schema.show` 并渲染出来。
     *
     * 这是本测试的核心 —— 没有它，「声明了但没渲染方」会再次静默发生。
     */
    public function test_frontend_consumes_the_show_section(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/resource.blade.php'
        );

        $this->assertStringContainsString(
            'schema.show',
            $blade,
            '前端没有读 schema.show —— Show 又变成"声明了但没渲染方"'
        );

        /*
         * ⚠️ 只断言「出现过 schema.show」是不够的 —— 反例验证抓到过这一点：
         * 把 `schema.show?.fields ?? []` 改成 `[]`（等于**根本不读字段**），
         * 上面那条断言照样通过（layout 那一行还带着 schema.show 字样）。
         *
         * 所以直接钉住**那一行赋值**：详情字段必须来自 schema.show.fields。
         * 用「包含该子串」而不是正则 —— 正则很容易被多行写法绕过，
         * 而这里要防的恰恰是「有人把这一行改掉」。
         */
        $this->assertStringContainsString(
            'schema.show?.fields ?? []',
            $blade,
            '详情字段必须从 schema.show.fields 取 —— 否则前端压根没消费 show()'
        );

        foreach (['showDetail', 'showRow', 'am-detail'] as $needle) {
            $this->assertStringContainsString($needle, $blade, "详情渲染缺 {$needle}");
        }
    }

    /**
     * 详情按钮要在**三种展示形态**下都出现。
     *
     * 三种形态各写一遍操作区，漏掉一个就会「卡片能看详情、表格不能」。
     */
    public function test_detail_button_exists_in_all_three_grid_modes(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/resource.blade.php'
        );

        /*
         * 数的是**按钮调用点**（`@click="showRow(row)"`），
         * 不是标识符出现次数 —— 函数定义本身也含 showRow(row)。
         * 第一版就数错了（4 次），测试红得对。
         */
        $count = substr_count($blade, '@click="showRow(row)"');

        $this->assertSame(
            3,
            $count,
            "详情按钮应出现 3 次（table/list/cards 各一），实际 {$count} 次"
        );
    }

    /**
     * 详情权限必须与 show 接口用**同一个权限节点**。
     *
     * 否则会出现「按钮能点、点了 403」这种自相矛盾的界面。
     */
    public function test_detail_ability_matches_the_show_endpoint_slug(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Http/Controllers/HomeController.php'
        );

        $this->assertStringContainsString(
            "'show' => \$can('show')",
            $src,
            'HomeController 必须提供 show 能力位（与 authorize_($uri, \'show\') 同源）'
        );

        // 接口侧的权限校验点也要还在
        $ctrl = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Http/Controllers/ResourceController.php'
        );

        $this->assertStringContainsString("authorize_(\$uri, 'show')", $ctrl);
    }

    /**
     * 🔴 两端 abilities 的键必须一致 —— 模板是**同一份**。
     *
     * ## 这条断言是被一次真实 500 逼出来的
     *
     * 给 Laravel 侧加了 `show`（详情页权限）之后忘了改 ThinkPHP 侧，
     * 结果是 TP 的**每个资源页面都 500**：
     * `Undefined array key "show"` —— 因为 TP 模板里也用 `$abilities['show']`。
     *
     * 两端共用同一份模板时，**模板用到的每个变量都是一份隐式契约**。
     * 没有这条断言，下次加按钮还会再踩一次。
     */
    public function test_abilities_keys_are_identical_across_drivers(): void
    {
        $extract = static function (string $path): array {
            $src = (string) file_get_contents($path);
            preg_match_all("/'(\w+)'\s*=>\s*\\\$can\('(\w+)'\)/", $src, $m);

            return $m[1];
        };

        $laravel = $extract(dirname(__DIR__, 2).'/src/Http/Controllers/HomeController.php');
        $think = $extract(dirname(__DIR__, 3).'/thinkphp/src/Http/HomeController.php');

        $this->assertNotEmpty($laravel, '没解析出 Laravel 侧的能力位');
        $this->assertNotEmpty($think, '没解析出 ThinkPHP 侧的能力位');

        $this->assertSame(
            $laravel,
            $think,
            '两端 abilities 的键必须一致 —— 模板是同一份，少一个键就是整页 500'
        );
    }

    /**
     * 详情渲染的模板必须**只用声明过的字段**（不允许前端自己猜字段）。
     */
    public function test_detail_view_iterates_declared_fields_only(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/resource.blade.php'
        );

        // 必须按 detailFields 迭代，而不是写死某些列名
        $this->assertMatchesRegularExpression(
            '/v-for="f in detailFields"/',
            $blade,
            '详情字段必须来自声明（detailFields），不能写死'
        );
    }
}
