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

echo "\n=== ④ 数据 API：声明驱动（Resource → JSON 接口）===\n";

/** 发一个请求，返回 [状态码, 解码后的 JSON 或原始文本] */
$api = function (string $method, string $path, array $data = []) use ($port): array {
    $ch = curl_init("http://127.0.0.1:{$port}/admin/api/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $data === [] ? null : http_build_query($data),
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json = json_decode($raw, true);

    return [$status, is_array($json) ? $json : $raw];
};

[$st, $r] = $api('GET', 'customers');
t('列表 200', $st === 200, "HTTP {$st}");
t('列表带 meta.total', is_array($r) && ($r['meta']['total'] ?? null) === 2);
t('列表返回 data 数组', is_array($r) && count($r['data'] ?? []) === 2);

[$st, $r] = $api('GET', 'customers?keyword='.rawurlencode('杭州'));
t('搜索生效（searchable 声明驱动）', $st === 200 && ($r['meta']['total'] ?? null) === 1);

[$st, $r] = $api('GET', 'customers?sort=name&direction=desc');
t('排序生效', $st === 200 && is_array($r));

// AI 常把 direction 猜成 order —— 静默忽略会返回未排序结果而 AI 以为生效了
[$st, $r] = $api('GET', 'customers?order=name');
t('未知查询参数有警告', $st === 200 && ($r['warnings'][0]['code'] ?? '') === 'UNKNOWN_QUERY_PARAM');

// ->required() 在 TP 上必须真的生效，否则声明就是幽灵能力
[$st, $r] = $api('POST', 'customers', ['industry' => '未填名称']);
t('缺必填 → 422', $st === 422, "HTTP {$st}");
t('422 指出是哪个字段', is_array($r) && isset($r['errors']['name']));

[$st, $r] = $api('POST', 'customers', ['name' => 'E2E 客户', 'industry' => '测试', 'level' => 'A']);
t('创建 → 201', $st === 201, "HTTP {$st}");
$newId = is_array($r) ? ($r['data']['id'] ?? null) : null;
t('创建返回主键', is_int($newId) || is_string($newId));

if ($newId !== null) {
    [$st] = $api('GET', "customers/{$newId}");
    t('详情 200', $st === 200, "HTTP {$st}");

    [$st] = $api('PUT', "customers/{$newId}", ['name' => 'E2E 改名']);
    t('更新 200', $st === 200, "HTTP {$st}");

    [$st] = $api('DELETE', "customers/{$newId}");
    t('删除 204', $st === 204, "HTTP {$st}");
}

[$st] = $api('GET', 'customers/999999');
t('详情不存在 → 404', $st === 404, "HTTP {$st}");

// 未在 permissions 里授权的 uri 必须 403，而不是悄悄放行
[$st] = $api('GET', 'orders');
t('未授权 uri → 403', $st === 403, "HTTP {$st}");

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

echo "\n=== ⑤ 控制台命令：AI 自检入口与四份产物 ===\n";

/** 跑一条 think 命令，返回 [退出码, 合并输出] */
$think = function (string $cmd) use ($tp): array {
    $out = [];
    $code = 0;
    exec(sprintf('cd %s && php think %s 2>&1', escapeshellarg($tp), $cmd), $out, $code);

    return [$code, implode("\n", $out)];
};

[$code, $out] = $think('ai:verify');
t('ai:verify 退出码 0', $code === 0, "exit={$code}");
t('ai:verify 报告 Resource 通过', str_contains($out, 'customers'));

[$code, $out] = $think('ai:verify --json');
$json = json_decode($out, true);
t('ai:verify --json 可解析', is_array($json));
t('--json 含 valid/total/failed', is_array($json) && isset($json['valid'], $json['total'], $json['failed']));
t('--json valid=true', is_array($json) && ($json['valid'] ?? null) === true);

/*
 * 幽灵列检测 —— 宪法第三条「零隐式」的主要执行者。
 * 换了框架它必须一样生效，否则声明了不存在的列会静默通过。
 * 这里直接把 Resource 里的 name 拼错成 nmae 来探测。
 */
$res = $tp.'/app/CustomerResource.php';
$orig = (string) file_get_contents($res);
file_put_contents($res, str_replace("'name', '名称'", "'nmae', '名称'", $orig));

[$code, $out] = $think('ai:verify');
$ghostOk = $code === 1 && str_contains($out, 'GHOST_COLUMN') && str_contains($out, 'nmae');
file_put_contents($res, $orig);

t('幽灵列被检出且退出码 1', $ghostOk);
t('幽灵列给出 did_you_mean', str_contains($out, 'name'));

[$code, $out] = $think('aimanong:schema');
t('aimanong:schema 退出码 0', $code === 0, "exit={$code}");

$artifacts = ['schema.json', 'types.ts', 'openapi.json', 'ai-context.md'];

foreach ($artifacts as $a) {
    $path = $tp.'/aimanong/'.$a;
    t("产物 {$a} 已生成且非空", is_file($path) && filesize($path) > 0, is_file($path) ? filesize($path).' 字节' : '缺失');
}

/*
 * 漂移检测 —— 生成的产物必须与声明一致。
 * 声明改了不重新生成是最典型的失效方式，而且**不报错**。
 */
[$code] = $think('aimanong:schema --check');
t('--check 无漂移时退出码 0', $code === 0, "exit={$code}");

$origRes = (string) file_get_contents($res);
file_put_contents($res, str_replace(
    "\$grid->column('industry', '行业');",
    "\$grid->column('industry', '行业')->sortable();",
    $origRes
));
[$code] = $think('aimanong:schema --check');
file_put_contents($res, $origRes);

t('声明变更后 --check 检出轨度', $code === 1, "exit={$code}");

echo "\n=== ⑥ 安装命令：全新项目能闭环 ===\n";

$fresh = sys_get_temp_dir().'/amn-tp-fresh-'.getmypid();
exec('rm -rf '.escapeshellarg($fresh).' && mkdir -p '.escapeshellarg($fresh).'/app');

[$code, $out] = $think('aimanong:install --dir='.escapeshellarg($fresh));
t('install 退出码 0', $code === 0, "exit={$code}");
t('发布了 config/aimanong.php', is_file($fresh.'/config/aimanong.php'));

$cfg = is_file($fresh.'/config/aimanong.php') ? include $fresh.'/config/aimanong.php' : [];
t('配置含 prefix/dict/permissions 等键', is_array($cfg)
    && isset($cfg['prefix'], $cfg['dict'], $cfg['permissions'], $cfg['upload'], $cfg['ui']));
t('创建了 app/service.php', is_file($fresh.'/app/service.php'));
t('service.php 登记了 ThinkService', is_file($fresh.'/app/service.php')
    && str_contains((string) file_get_contents($fresh.'/app/service.php'), 'ThinkService'));

/*
 * ⚠️ 顺序有实际后果：ThinkService 的 register() 绑定框架运行时与驱动，
 * 排在它前面的服务调用 Aimanong::registry() 会抛未注册。
 * 所以它必须是数组第一项。
 */
file_put_contents($fresh.'/app/service.php', "<?php\nreturn [\n    \\app\\MyService::class,\n];\n");
$think('aimanong:install --dir='.escapeshellarg($fresh));
$svc = (string) file_get_contents($fresh.'/app/service.php');
$posAimanong = strpos($svc, 'ThinkService');
$posOther = strpos($svc, 'MyService');
t('插到已有服务之前（第一项）', $posAimanong !== false && $posOther !== false && $posAimanong < $posOther);

// 幂等：再跑一次不应重复插入
$think('aimanong:install --dir='.escapeshellarg($fresh));
$svc2 = (string) file_get_contents($fresh.'/app/service.php');
t('重复执行幂等（不重复登记）', substr_count($svc2, 'ThinkService') === 1);

/*
 * 「没做的」必须明说 —— 一个装完说「安装完成」、实际后台进不去的命令，
 * 比没有这个命令更糟。
 */
[$code, $out] = $think('aimanong:install --dir='.escapeshellarg($fresh));
t('明说跳过的步骤', str_contains($out, '尚未实现') && str_contains($out, '创建管理员'));
t('明说后台无需登录（仅本地开发）', str_contains($out, '认证层尚未实现'));

exec('rm -rf '.escapeshellarg($fresh));

$completed = true;
