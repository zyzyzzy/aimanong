<?php

declare(strict_types=1);

/*
 * ThinkPHP 端到端：**从零搭的 ThinkPHP 项目里，后台页面真的能打开**。
 *
 * ## 为什么必须有这个脚本
 *
 * `/admin` 200 曾经只在本机手验过一次。而这一类问题——
 * 转换器「跑成功但产物不对」—— **不报错**：
 * 退出码 0、模板 4 条规则全过、think-template 也能编译，
 * 页面只是渲染得乱七八糟。实测踩过三次：
 *
 *   ① {volist} 用字符串键数组算 `$key % 2` → TypeError
 *   ② @switch 只标注不转换 → 所有分支都执行 → Undefined array key
 *   ③ 转换器两个正则错误 → 分支全退化成 {else}
 *
 * 单测、PHPStan、代码风格、模板规则——**没有一个能拦住它们**。
 * 唯一的裁判是「把页面真跑一遍并检查内容」。
 *
 * 用法：
 *   bash scripts/setup-thinkphp-demo.sh
 *   php tests/e2e/thinkphp-e2e.php
 *
 * 环境变量 TP_PATH 指定项目路径，默认 ../thinkphp-demo。
 */

$tp = getenv('TP_PATH') ?: __DIR__.'/../../../thinkphp-demo';

if (! is_file($tp.'/vendor/autoload.php')) {
    fwrite(STDERR, "找不到 ThinkPHP 演示项目: {$tp}\n");
    fwrite(STDERR, "先跑：bash scripts/setup-thinkphp-demo.sh\n");
    exit(1);
}

$pass = 0;
$fail = 0;
$completed = false;

/*
 * 没跑到结尾就退出 = 中途崩了 = 失败。
 *
 * ⚠️ 这个守卫是被真实事故逼出来的：e2e 曾在 `require vendor/autoload.php`
 * 阶段就挂掉（autoloader 指向已删除的文件），**退出码却是 0** ——
 * 于是上层打印「✅ 通过」，而实际上一个断言都没跑。
 */
register_shutdown_function(function () use (&$pass, &$fail, &$completed): void {
    if (! $completed) {
        fwrite(STDERR, "\n❌ thinkphp-e2e 未跑完就退出了（中途崩溃）\n");
        exit(1);
    }

    printf("\n通过 %d / %d\n", $pass, $pass + $fail);
    exit($fail > 0 ? 1 : 0);
});

function t(string $name, bool $ok, string $note = ''): void
{
    global $pass, $fail;
    printf("  %-52s %s%s\n", $name, $ok ? '✅' : '❌', $note !== '' ? "  {$note}" : '');
    $ok ? $pass++ : $fail++;
}

echo "=== ① 依赖纯净性（拆包的根本目的）===\n";
t('vendor/illuminate 不存在', ! is_dir($tp.'/vendor/illuminate'));
t('aimanong/core 已装', is_dir($tp.'/vendor/aimanong/core'));
t('aimanong/thinkphp 已装', is_dir($tp.'/vendor/aimanong/thinkphp'));

echo "\n=== ② 数据层在真实 ThinkPHP 上可用 ===\n";

require $tp.'/vendor/autoload.php';
require $tp.'/app/Customer.php';

$app = new think\App;
$app->initialize();

Aimanong\ThinkPHP\ThinkService::bootstrap();

t('4 个驱动已注册', count(Aimanong\Support\Drivers::registeredNames()) === 4);
t('公开入口已绑定', Aimanong\Aimanong::runtime() instanceof Aimanong\ThinkPHP\ThinkRuntime);
t('Aimanong::url()', Aimanong\Aimanong::url('orders') === '/admin/orders', Aimanong\Aimanong::url('orders'));

$ds = new Aimanong\ThinkPHP\ThinkDataSource(app\Customer::class);
$page = $ds->paginate();
t('DataSource::paginate()', $page->total() === 2 && count($page->items()) === 2);
t('perPage() 走 listRows()', $page->perPage() === 20);
t('DataSource::find() 找不到抛异常', (function () use ($ds): bool {
    try {
        $ds->find(999999);

        return false;
    } catch (think\db\exception\ModelNotFoundException) {
        return true;
    }
})());

/*
 * 幽灵列检测 —— 宪法第三条「零隐式」的主要执行者。
 * 换了框架它必须一样生效，否则声明了不存在的列会静默通过。
 */
$intro = new Aimanong\ThinkPHP\ThinkIntrospector;
t('内省：columnsOf 含 name', in_array('name', $intro->columnsOf('customers'), true));
t('内省：isRelation 不误判普通值', $intro->isRelation('not-a-relation') === false);
t('内省：访问器识别为虚拟属性', $intro->isVirtualAttribute(new app\Customer, 'level_label') === true);
t('内省：拼错列名不放过', $intro->isVirtualAttribute(new app\Customer, 'nmae') === false);
t('内省：$type 声明的列算真实列', $intro->isVirtualAttribute(new app\Customer, 'credit_days') === true);

echo "\n=== ③ 真实 HTTP：/admin 能打开 ===\n";

$port = 8897;
$serve = sprintf('%s -S 127.0.0.1:%d -t %s/public', PHP_BINARY, $port, escapeshellarg($tp));
$log = sys_get_temp_dir().'/amn-tp-serve.log';

exec(sprintf('pkill -f "127.0.0.1:%d" 2>/dev/null; true', $port));
$pid = null;
$proc = proc_open($serve, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
usleep(2500000);

$url = "http://127.0.0.1:{$port}/admin";
$body = '';
$code = 0;

for ($i = 0; $i < 20; $i++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code > 0) {
        break;
    }

    usleep(500000);
}

t('/admin 返回 200', $code === 200, "HTTP {$code}");

if ($code !== 200) {
    // 失败时把错误页的关键信息摘出来 —— 否则只有一个 500 无从下手
    if (preg_match_all('/<h1[^>]*>(.*?)<\/h1>/s', $body, $m)) {
        echo '     错误: '.trim(strip_tags($m[1][0]))."\n";
    }

    echo "     服务日志尾部:\n";

    foreach (array_slice(file($log) ?: [], -5) as $line) {
        echo '       '.trim($line)."\n";
    }
}

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

/*
 * 内容断言 —— 这一步才是真正的裁判。
 *
 * 只看状态码不够：模板渲染出错时 TP 会返回一个 **200 的错误页**
 * 或者在错误处理器里返回 500，但即使 200，页面也可能是空壳。
 */
if ($code === 200) {
    t('不是错误页', ! str_contains($body, '系统发生错误') && ! str_contains($body, 'Exception trace'));
    t('含 <title>', (bool) preg_match('/<title>/', $body));

    if (preg_match('/<title>(.*?)<\/title>/s', $body, $m)) {
        t('标题含品牌名', str_contains($m[1], 'Aimanong'), trim($m[1]));
    }

    t('侧边栏已渲染', str_contains($body, 'am-sidebar'));
    t('主题面板已渲染', str_contains($body, 'am-themepanel'));
    t('SVG 图标已渲染', str_contains($body, '<svg'));

    /*
     * 主题面板是 @switch 转换正确性的直接证据：
     *   type=color 的分支 → 取色器
     *   type=radio 的分支 → 选项文字
     * 转换错时（所有分支都执行）这里会炸在 choices 上；
     * 分支退化成 {else} 时选项文字不会出现。
     */
    t('switch: color 分支渲染取色器', str_contains($body, 'type="color"'));
    t('switch: radio 分支渲染选项', str_contains($body, '跟随系统') && str_contains($body, '浅色') && str_contains($body, '深色'));
    t('版本号已渲染', (bool) preg_match('/\b1\.\d+\.\d+\b/', $body));
}

$completed = true;
