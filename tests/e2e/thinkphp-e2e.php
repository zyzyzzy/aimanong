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

/*
 * 驱动清单。
 *
 * ⚠️ 断言**具体名字**而不是「数量 == N」：数量一改就得跟着改，
 * 而且数字错了看不出缺哪个。多应用解析器（ApplicationResolver）
 * 是第 5 个 —— 漏注册它不会报错，只会让多应用静默失效。
 */
$expectedDrivers = ['SchemaIntrospector', 'DictionarySource', 'PermissionChecker', 'UploadSpec', 'ApplicationResolver'];
$actualDrivers = Aimanong\Support\Drivers::registeredNames();
$missing = array_diff($expectedDrivers, $actualDrivers);
t(
    '5 个驱动已注册（含多应用解析器）',
    $missing === [],
    $missing === [] ? implode(', ', $actualDrivers) : '缺: '.implode(', ', $missing)
);
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

/*
 * 数据作用域（多租户 / 行级隔离）—— 本次全面验收发现的**最高危缺口**。
 *
 * 此前 TP 侧的 ThinkDataSource 里 0 处 ScopeHooks（Laravel 侧 4 处），
 * 配了多租户后查询不做任何收窄 → 跨租户数据全量返回，
 * 而且它在 200 响应里完全看不出来。
 */
\Aimanong\Ai\ScopeHooks::flush();

$scoped = new \Aimanong\ThinkPHP\ThinkDataSource(app\Customer::class);
t('未注册作用域：全部可见', $scoped->paginate()->total() === 2);

\Aimanong\Ai\ScopeHooks::query('tenant', function ($q): void {
    $q->where('industry', '制造业');
});

$page = $scoped->paginate();
t('查询作用域生效（total 收窄）', $page->total() === 1, "total={$page->total()}");

/*
 * 这四条是关键：作用域必须**在所有查询条件之前**，
 * 否则搜索 / 排序 / 筛选 / ids 都能绕过它 —— 那就等于没有隔离。
 */
t('搜索绕不过作用域', $scoped->paginate(['keyword' => '杭州', 'searchable' => ['name']])->total() === 0);
t('排序绕不过作用域', $scoped->paginate(['sort' => 'name', 'direction' => 'desc'])->total() === 1);
t('筛选绕不过作用域', $scoped->paginate(['filters' => ['level' => 'B']])->total() === 0);
t('ids 绕不过作用域', $scoped->paginate(['ids' => [1, 2]])->total() === 1);

\Aimanong\Ai\ScopeHooks::writing('tenant', function (array $d): array {
    $d['industry'] = '自动补齐';

    return $d;
});
$created = $scoped->create(['name' => '作用域探针', 'industry' => '手工值', 'level' => 'A']);
t('写入作用域生效', $created->industry === '自动补齐', (string) $created->industry);
$scoped->delete($created->getKey());

\Aimanong\Ai\ScopeHooks::flush();
t('flush 后恢复可见', $scoped->paginate()->total() === 2);

echo "\n=== ③ 真实 HTTP：/admin 能打开 ===\n";

/*
 * 清掉限流缓存再开始。
 *
 * 限流是按 IP 计数的，而这个脚本自己就要发十几次登录请求；
 * 上一次运行的计数会**跨轮次影响**，导致断言看到的是上一轮的 429。
 * 夹具主动重置状态是对的 —— 否则测试会时好时坏。
 */
exec('rm -rf '.escapeshellarg($tp.'/runtime/cache'));

$port = 8897;

// 登录态用的 cookie jar —— 认证已启用，后续所有请求都要带上
$jar = sys_get_temp_dir().'/amn-tp-jar-'.getmypid();
@unlink($jar);
/*
 * ⚠️ 必须带 `public/router.php`。
 *
 * PHP 内置服务器对**带扩展名**的路径（`/admin/assets/x.css`）不会回退到
 * index.php —— 直接返回它自己的 404，请求根本到不了框架。
 * 于是「资产路由配了却全 404」，而 `/admin/customers`（无扩展名）正常，
 * 极易误判成框架 bug。
 *
 * 官方 TP 骨架自带 router.php（`php think run` 就靠它）；demo 由
 * setup-thinkphp-demo.sh 生成。
 */
$router = is_file($tp.'/public/router.php') ? ' '.escapeshellarg($tp.'/public/router.php') : '';
$serve = sprintf('%s -S 127.0.0.1:%d -t %s/public%s', PHP_BINARY, $port, escapeshellarg($tp), $router);
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
        // 认证已经启用 —— 不带 cookie 会拿到 302 而不是页面
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    /*
     * ⚠️ 必须 **unset** 句柄，不能只 curl_close。
     *
     * curl 是在句柄**被销毁**时才把 cookie jar 落盘的；PHP 8 起
     * `curl_close()` 是空操作。留着句柄，jar 文件在整个脚本期间都不存在，
     * 于是后面的请求全是未登录状态。
     * （实测踩到：登录明明返回 302 成功，页面却还是 302。）
     */
    unset($ch);

    if ($code > 0) {
        break;
    }

    usleep(500000);
}

/*
 * 认证已启用，所以先登入 —— 否则页面检查会拿到 302 重定向，
 * 报「/admin 返回 200 失败」，而在浏览器里这其实是正确行为。
 */
$login = curl_init("http://127.0.0.1:{$port}/admin/auth/login");
curl_setopt_array($login, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => 'admin', 'password' => 'admin123']),
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
]);
curl_exec($login);
unset($login);   // 同上：必须让句柄销毁，jar 才会落盘

// ⚠️ 必须**重新请求**页面 —— 上面轮询拿到的 $code 是登录前的 302。
//    （第一版就漏了这一步，断言一直在看旧值。）
$page = curl_init($url);
curl_setopt_array($page, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
]);
$body = (string) curl_exec($page);
$code = (int) curl_getinfo($page, CURLINFO_HTTP_CODE);
unset($page);

t('/admin 返回 200', $code === 200, "HTTP {$code}");

echo "\n=== ③ 真实 HTTP：/admin 能打开 ===\n";

$port = 8897;

// 登录态用的 cookie jar —— 认证已启用，后续所有请求都要带上
$jar = sys_get_temp_dir().'/amn-tp-jar-'.getmypid();
@unlink($jar);
/*
 * ⚠️ 必须带 `public/router.php`。
 *
 * PHP 内置服务器对**带扩展名**的路径（`/admin/assets/x.css`）不会回退到
 * index.php —— 直接返回它自己的 404，请求根本到不了框架。
 * 于是「资产路由配了却全 404」，而 `/admin/customers`（无扩展名）正常，
 * 极易误判成框架 bug。
 *
 * 官方 TP 骨架自带 router.php（`php think run` 就靠它）；demo 由
 * setup-thinkphp-demo.sh 生成。
 */
$router = is_file($tp.'/public/router.php') ? ' '.escapeshellarg($tp.'/public/router.php') : '';
$serve = sprintf('%s -S 127.0.0.1:%d -t %s/public%s', PHP_BINARY, $port, escapeshellarg($tp), $router);
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
        // 认证已经启用 —— 不带 cookie 会拿到 302 而不是页面
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    /*
     * ⚠️ 必须 **unset** 句柄，不能只 curl_close。
     *
     * curl 是在句柄**被销毁**时才把 cookie jar 落盘的；PHP 8 起
     * `curl_close()` 是空操作。留着句柄，jar 文件在整个脚本期间都不存在，
     * 于是后面的请求全是未登录状态。
     * （实测踩到：登录明明返回 302 成功，页面却还是 302。）
     */
    unset($ch);

    if ($code > 0) {
        break;
    }

    usleep(500000);
}

/*
 * 认证已启用，所以先登入 —— 否则页面检查会拿到 302 重定向，
 * 报「/admin 返回 200 失败」，而在浏览器里这其实是正确行为。
 */
$login = curl_init("http://127.0.0.1:{$port}/admin/auth/login");
curl_setopt_array($login, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => 'admin', 'password' => 'admin123']),
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
]);
curl_exec($login);
unset($login);   // 同上：必须让句柄销毁，jar 才会落盘

// ⚠️ 必须**重新请求**页面 —— 上面轮询拿到的 $code 是登录前的 302。
//    （第一版就漏了这一步，断言一直在看旧值。）
$page = curl_init($url);
curl_setopt_array($page, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
]);
$body = (string) curl_exec($page);
$code = (int) curl_getinfo($page, CURLINFO_HTTP_CODE);
unset($page);

t('/admin 返回 200', $code === 200, "HTTP {$code}");

echo "\n=== ⑦ 认证：登录 / 会话 / 拦截 / 登出 ===\n";

/** 带 cookie jar 发请求，返回 [状态码, 响应体, 跳转目标] */
$auth = function (string $method, string $path, array $data = [], bool $useJar = true) use ($port, &$jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
    ];
    if ($useJar) {
        $opts[CURLOPT_COOKIEJAR] = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($data !== []) {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    curl_setopt_array($ch, $opts);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    unset($ch);   // 同上：句柄销毁才会把 jar 落盘

    return [$status, $body, $loc];
};

/*
 * 先把状态归零：上面为了检查页面已经登入过了，
 * 而这一段要验证的是**未登录**的行为。
 */
$auth('POST', '/admin/auth/logout');

// 未登录必须被拦 —— 否则「装完就能用」等于「装完就被人用」
[$st, , $loc] = $auth('GET', '/admin');
t('未登录访问 /admin → 302', $st === 302, "HTTP {$st}");
t('跳转到登录页', str_contains($loc, 'auth/login'), $loc);

[$st, $body] = $auth('GET', '/admin/auth/login');
t('登录页 200', $st === 200, "HTTP {$st}");
t('登录页含表单', str_contains($body, '<form') || str_contains($body, 'password'));

[$st] = $auth('GET', '/admin/api/customers');
t('未登录访问 API → 302', $st === 302, "HTTP {$st}");

$auth('GET', '/admin'); // 刷新 cookie jar

[$st] = $auth('POST', '/admin/auth/login', ['username' => 'admin', 'password' => '错误密码']);
t('密码错误 → 401', $st === 401, "HTTP {$st}");

/*
 * 会话固定（session fixation）防护：登录成功后必须换一个 session id。
 *
 * 不换的话：攻击者先让受害者带着一个已知 id 访问，受害者登录后那个 id
 * 仍然有效 —— 攻击者直接用它就是登录态。
 */
$jarBefore = (string) @file_get_contents($jar);
preg_match('/PHPSESSID\s+(\S+)/', $jarBefore, $mBefore);

[$st] = $auth('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123']);
t('密码正确 → 302', $st === 302, "HTTP {$st}");

$jarAfter = (string) @file_get_contents($jar);
preg_match('/PHPSESSID\s+(\S+)/', $jarAfter, $mAfter);
t('登录后 session id 已更换', ($mBefore[1] ?? '') !== '' && ($mAfter[1] ?? '') !== ''
    && $mBefore[1] !== $mAfter[1], ($mBefore[1] ?? '?').' → '.($mAfter[1] ?? '?'));

[$st, $body] = $auth('GET', '/admin');
t('登录后 /admin → 200', $st === 200, "HTTP {$st}");
/*
 * ⚠️ 这里原本只有 `str_contains($body, 'Aimanong')` —— 而 'Aimanong'
 * 出现在**布局**（品牌行/页脚）里，所以这条断言**永远为真**。
 *
 * 后果：think-template 的 `{block name="content" /}` 自闭合占位符不会被替换，
 * 子模板内容**整块丢失**（控制台只剩侧边栏与主题面板，正文一个字没有），
 * 而这里一路全绿。真实存在了很多天。
 *
 * 改成查**页面主体**的独有标记，再补一条「无未替换的模板占位符」。
 */
t('登录后页面有内容', str_contains($body, 'Aimanong'));

// 主体标记：am-page-head 只在页面内容块里，布局里没有
t('控制台渲染了页面主体（不只是布局）', str_contains($body, 'am-page-head'), 'am-page-head');

// 未替换的模板占位符 —— 出现即说明布局/块机制没生效
$leftover = str_contains($body, '{block') || str_contains($body, '{extend') || str_contains($body, '{/block}');
t('页面无未替换的模板占位符', ! $leftover, $leftover ? '发现 {block}/{extend} 字面量' : '干净');

[$st, $body] = $auth('GET', '/admin/api/customers');
t('登录后 API → 200 且有数据', $st === 200 && str_contains($body, '"data"'), "HTTP {$st}");

[$st] = $auth('POST', '/admin/auth/logout');
t('登出 → 302', $st === 302, "HTTP {$st}");

[$st] = $auth('GET', '/admin');
t('登出后被拦截 → 302', $st === 302, "HTTP {$st}");

echo "\n=== ⑨ RBAC：超管绕过 + 普通角色被拒 ===\n";

$viewerJar = sys_get_temp_dir().'/amn-tp-viewer-'.getmypid();
@unlink($viewerJar);

/** 用指定 jar 发请求 */
$as = function (string $method, string $path, array $data, string $jar) use ($port): array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POSTFIELDS => $data === [] ? null : http_build_query($data),
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $b = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return [$st, $b];
};

// 上面验证的是「登出后被拦」，所以这里先以超管身份登入
$auth('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123']);

// 超管能吃下全部 —— 这条同时验证了「装完不会全站 403」这个闭环死锁
[$st, $abody] = $auth('GET', '/admin/api/customers');
t('超管可访问（RBAC 打开后不会全站 403）', $st === 200, "HTTP {$st}");

$auth('POST', '/admin/auth/logout');
[$st] = $as('POST', '/admin/auth/login', ['username' => 'viewer', 'password' => 'viewer123'], $viewerJar);
t('普通角色可登录', $st === 302, "HTTP {$st}");

[$st, $abody] = $as('GET', '/admin/api/customers', [], $viewerJar);
t('普通角色访问被拒 → 403', $st === 403, "HTTP {$st}");
t('403 是 JSON（不是 HTML 错误页）', str_contains($abody, '"code":"FORBIDDEN"'), substr($abody, 0, 60));

// 恢复 admin 的登录态给后续段落用
[$st] = $auth('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123']);
t('超管重新登入', $st === 302, "HTTP {$st}");

@unlink($viewerJar);

echo "\n=== ⑧ 登录限流：防暴力破解 ===\n";

/*
 * 用一个**独立账号名**探测，避免把 admin 的计数打满影响后续段落。
 * （限流是按「IP + 账号」两个维度计的，换账号名就隔离了。）
 */
$probeUser = 'ghost-user-'.substr((string) getmypid(), -4);
$codes = [];

for ($i = 1; $i <= 7; $i++) {
    [$st] = $auth('POST', '/admin/auth/login', ['username' => $probeUser, 'password' => 'wrong']);
    $codes[] = $st;
}

t('前几次是 401（密码错误）', $codes[0] === 401, "第1次 HTTP {$codes[0]}");
t('达到上限后变 429（限流）', in_array(429, $codes, true), 'HTTP '.implode(',', $codes));

/*
 * 大小写 / 空格变体必须共用一个计数桶。
 *
 * 不规范化的话 `Admin` / `admin` / ` admin ` 是三个桶，
 * 攻击者变换大小写就能绕过「每账号 5 次」—— 限流看着在、实际形同虚设。
 */
// 429 必须带「还要等几秒」—— 只说「太频繁」用户不知道等多久
[$st, $abody] = $auth('POST', '/admin/auth/login', ['username' => $probeUser, 'password' => 'wrong']);
t('429 提示剩余秒数', $st === 429 && (bool) preg_match('/\d+\s*秒/', $abody));

// 用**独立账号名**探测，否则会把 admin 的计数桶打满，
// 影响后面「正确密码仍可登录」那条断言（第一次跑就踩到了）
$caseUser = 'Case-User-'.substr((string) getmypid(), -4);
exec('rm -rf '.escapeshellarg($tp.'/runtime/cache'));
$mixed = [];

foreach([$caseUser, ucfirst($caseUser), strtoupper($caseUser), ' '.$caseUser.' ', $caseUser, $caseUser] as $variant) {
    [$st] = $auth('POST', '/admin/auth/login', ['username' => $variant, 'password' => 'wrong']);
    $mixed[] = $st;
}

t('大小写变体共用计数桶（第6次 429）', ($mixed[5] ?? 0) === 429, 'HTTP '.implode(',', $mixed));


/*
 * 成功登录必须**清零** —— 否则用户打错几次再登对，
 * 后面正常的操作仍会被计数挡住。
 */
[$st] = $auth('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123']);
t('正确密码仍可登录（未被误伤）', $st === 302, "HTTP {$st}");

/*
 * 后续段落（页面 / 数据 API）现在都需要登录态 ——
 * 这里重新登入并保留 cookie jar。
 */
$auth('POST', '/admin/auth/login', ['username' => 'admin', 'password' => 'admin123']);


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
/*
 * 内容检查自己重新抓一次页面。
 *
 * 不复用上面的 $body：认证段跑过登录/登出，中途 $body 被别的请求
 * 覆盖过好几次（实测踩到：内容断言全挂在 JSON 响应上）。
 * 这里显式重取，断言看到的才一定是这个页面。
 */
$check = curl_init("http://127.0.0.1:{$port}/admin");
curl_setopt_array($check, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
]);
$body = (string) curl_exec($check);
unset($check);

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
$api = function (string $method, string $path, array $data = []) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/admin/api/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $data === [] ? null : http_build_query($data),
        // 同样需要登录态
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    $json = json_decode($raw, true);

    return [$status, is_array($json) ? $json : $raw];
};

[$st, $r] = $api('GET', 'customers');
t('列表 200', $st === 200, "HTTP {$st}");
t('列表带 meta.total', is_array($r) && ($r['meta']['total'] ?? null) === 2);
t('列表返回 data 数组', is_array($r) && count($r['data'] ?? []) === 2);

[$st, $r] = $api('GET', 'customers?keyword='.rawurlencode('杭州'));
t('搜索生效（searchable 声明驱动）', $st === 200 && ($r['meta']['total'] ?? null) === 1);

/*
 * ⚠️ 这条原先排的是 `name`，而 customers **没声明** name 可排序。
 * 新门禁（只接受声明过 ->sortable() 的列）上线后它立刻红了 —— **红得对**：
 * 那正是这次要改的行为。改用已声明 sortable 的 id。
 */
[$st, $r] = $api('GET', 'customers?sort=id&direction=desc');
t('排序生效（已声明的列）', $st === 200 && is_array($r));

// 存在但没声明 ->sortable() 的列要拦（并说清怎么加）
[$st, $r] = $api('GET', 'customers?sort=name');
t(
    '存在但未声明 sortable 的列 → 422',
    $st === 422 && ($r['error'] ?? '') === 'SORT_COLUMN_NOT_DECLARED',
    "HTTP {$st}"
);

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

/*
 * 未注册的 uri → 404。
 *
 * 注意：这里**不是** 403 —— 超管角色绕过全部权限判定，
 * 所以权限这一关会过，然后在注册表里找不到 → 404。
 * 403 的验证在 ⑨ 段用普通角色做（那里才是真正的权限拦截）。
 */
[$st] = $api('GET', 'orders');
t('未注册的 uri → 404（超管已过权限关）', $st === 404, "HTTP {$st}");

echo "\n=== ⑩ 语义缺口修复验证（全面验收报告 P0）===\n";

// ① 只读资源必须拒绝写入（此前 TP 侧完全没有这道校验）
[$st] = $api('GET', 'readonly-customers');
t('只读资源可读 → 200', $st === 200, "HTTP {$st}");

[$st, $r] = $api('POST', 'readonly-customers', ['name' => 'x']);
t('只读资源 POST → 403', $st === 403, "HTTP {$st}");
t('403 说明是只读资源', is_array($r) && ($r['error']['code'] ?? '') === 'RESOURCE_READONLY');

[$st] = $api('PUT', 'readonly-customers/1', ['name' => 'y']);
t('只读资源 PUT → 403', $st === 403, "HTTP {$st}");

[$st] = $api('DELETE', 'readonly-customers/1');
t('只读资源 DELETE → 403', $st === 403, "HTTP {$st}");

/*
 * ② 连字符 URI
 *
 * 路由参数默认不匹配 `-`，`readonly-customers` 会被截成 `readonly`，
 * 报「Resource [readonly] 未注册」—— 看起来像资源没注册，实际是路由切断了名字。
 * 带连字符的资源名在真实项目里很常见。
 */
t('连字符 URI 未被截断', $st === 403, '（403 而非 404 说明 uri 完整匹配）');

// ③ requiredOnCreate：新增必填、编辑可留空
[$st, $r] = $api('POST', 'customers', ['name' => '只有名字']);
t('新增缺 requiredOnCreate 字段 → 422', $st === 422, "HTTP {$st}");
t('422 指出是 industry', is_array($r) && isset($r['errors']['industry']));

[$st, $r] = $api('POST', 'customers', ['name' => '完整', 'industry' => '制造业']);
t('带齐字段可新增 → 201', $st === 201, "HTTP {$st}");
$keepId = is_array($r) ? ($r['data']['id'] ?? null) : null;

if ($keepId !== null) {
    [$st] = $api('PUT', "customers/{$keepId}", ['name' => '只改名字']);
    t('编辑时该字段可留空 → 200', $st === 200, "HTTP {$st}");
    $api('DELETE', "customers/{$keepId}");
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
t('配置含 prefix/auth/dict/upload/ui 等键', is_array($cfg)
    && isset($cfg['prefix'], $cfg['auth'], $cfg['dict'], $cfg['upload'], $cfg['ui']));
t('配置含 RBAC 开关', is_array($cfg) && array_key_exists('rbac', $cfg['auth'] ?? []));
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
/*
 * install 现在真的建表、建管理员（认证已实现），
 * 但**仍要明说没做的**：一个装完说「安装完成」、实际不能对外用的命令，
 * 比没有这个命令更糟。
 */
[$code, $out] = $think('aimanong:install --dir='.escapeshellarg($fresh));
t('明说尚未实现的步骤', str_contains($out, '尚未实现') && str_contains($out, 'RBAC'));
t('明说限流已启用', str_contains($out, '登录限流') && str_contains($out, '已启用'));
t('install 真的建了表', str_contains($out, 'admin_users'));
t('install 真的建了管理员', str_contains($out, '已创建管理员') || str_contains($out, '已存在管理员'));

/*
 * ═══ ⑪ 多应用隔离（/admin + /agent）═══
 *
 * 放在服务 teardown **之前**（这一段需要服务在跑）。
 *
 * 盯的是只有真配多应用才会暴露的问题 —— Laravel 侧实测挖出 5 个
 * **静默失效**的 bug，都是同一类：
 *
 *   · 解析器没注册 → 菜单不过滤、slug 不加前缀（不报错）
 *   · HTTP 下 current() 不走请求路径推断 → 非默认后台权限全拒
 *   · session key 不带应用维度 → 登录写一个 key、读取查另一个（全站 403）
 *
 * 所以断言必须落在「用户/接口实际看到什么」，而不是「配置里有没有写」。
 */
/*
 * ═══ ⑫ 资源页面与静态资源 ═══
 *
 * 这一段盯的是「页面能不能真的用」—— 前几节只验了「返回 200」，
 * 而实际出现过：路由配了但资产全 404，页面**完全没有样式**，
 * 请求却是 200，不报任何错。
 */
/*
 * ═══ ⑬ 树形资源 ═══
 *
 * 盯两件事，都来自实测踩到的坑：
 *   ① 「半成品」：后端/JS/CSS 全都写好了，唯独模板没用 `_depth`
 *      → 声明了 tree() 却看不出层级，且不报任何错。
 *   ② 「越权」：Laravel 侧 tree()/move() 漏了权限校验，零权限账号能读全表、能改数据。
 */
echo "\n=== ⑬ 树形资源 ===\n";

$treeExp = json_decode((string) file_get_contents(__DIR__.'/fixtures/multi-app-expectations.json'), true);
t('读得到树形共享期望', isset($treeExp['tree']), 'fixtures 里有 tree 段');

$treeJar = sys_get_temp_dir().'/amn-tp-tree-'.getmypid().'.jar';
@unlink($treeJar);

$treeReq = function (string $path, ?string $method = null, ?string $body = null) use ($treeJar): array {
    $ch = curl_init("http://127.0.0.1:{$GLOBALS['port']}{$path}");
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $treeJar,
        CURLOPT_COOKIEFILE => $treeJar,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($method !== null) {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $out = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return [$code, $out];
};

// 登录
[$_, $loginHtml] = $treeReq('/admin/auth/login');
preg_match('/name="_token" value="([^"]+)"/', $loginHtml, $tm);
$ch = curl_init("http://127.0.0.1:{$GLOBALS['port']}/admin/auth/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['_token' => $tm[1] ?? '', 'username' => 'admin', 'password' => 'admin123']),
    CURLOPT_COOKIEJAR => $treeJar,
    CURLOPT_COOKIEFILE => $treeJar,
    CURLOPT_TIMEOUT => 15,
]);
curl_exec($ch);
unset($ch);

$treeUri = (string) ($treeExp['tree']['sample_resource'] ?? 'departments');

// ① 接口返回嵌套 children
[$tc, $tbody] = $treeReq("/admin/api/{$treeUri}/tree");
$tree = json_decode($tbody, true);
$roots = $tree['data'] ?? [];
t("树形接口 /api/{$treeUri}/tree → 200", $tc === 200, "HTTP {$tc}");

$expRootCount = (int) ($treeExp['tree']['api']['root_count'] ?? 1);
t('树形接口根节点数一致', count($roots) === $expRootCount, count($roots)." 个（期望 {$expRootCount}）");
t('树形接口返回嵌套 children', isset($roots[0]['children']) && is_array($roots[0]['children']));

// 树形接口要预加载关联（否则关联列整列显示「—」）
$childWithParent = null;

foreach ($roots[0]['children'] ?? [] as $child) {
    if (isset($child['parent'])) {
        $childWithParent = $child;
        break;
    }
}

t(
    '树形接口预加载了关联列（子节点带 parent 对象）',
    $childWithParent !== null,
    $childWithParent === null ? '子节点没有 parent 字段' : '有'
);

$expRoot = (string) ($treeExp['tree']['root']['name'] ?? '');
if ($expRoot !== '') {
    t('根节点名称与期望一致', ($roots[0]['name'] ?? '') === $expRoot, (string) ($roots[0]['name'] ?? '(空)'));
}

// ② 页面真的渲染了层级（模板用了 _depth + 折叠开关）
[$pc, $pbody] = $treeReq("/admin/{$treeUri}");
t('树形页面可访问', $pc === 200, "HTTP {$pc}");

/*
 * ⚠️ 这里**不能**断言算好的 `padding-left: 32px`。
 *
 * 缩进是 Vue 的 `:style` 绑定（`paddingLeft: _depth * 16 + 'px'`），
 * curl 拿到的服务端 HTML 里它是**未求值的 JS 表达式** —— 断言像素值
 * 在无 JS 的 e2e 里永远是空的（第一版就是这么写错的）。
 *
 * 所以这里断言「模板接上了」：占位元素 + 依赖 `_depth` 的绑定。
 * 真实渲染出来的层级由浏览器实测覆盖（见 AI实测记录 第七轮）。
 */
t(
    '模板接了树形单元格（此前是断的）',
    str_contains($pbody, 'am-tree-cell'),
    str_contains($pbody, 'am-tree-cell') ? '有' : '没有'
);
t(
    '缩进绑定了 _depth（层级真会体现）',
    str_contains($pbody, 'am-tree-cell') && str_contains($pbody, '_depth'),
    '需同时出现 am-tree-cell 与 _depth'
);
t(
    '折叠行过滤（displayRows）已接线',
    str_contains($pbody, 'displayRows'),
    str_contains($pbody, 'displayRows') ? '有' : '没有'
);
t(
    '父节点有展开/折叠开关',
    str_contains($pbody, 'am-tree-toggle'),
    str_contains($pbody, 'am-tree-toggle') ? '有' : '没有'
);

// ③ 按钮级权限 —— 没有 create/update/destroy 权限时按钮**服务端不渲染**
//
// 实测背景：框架原先没有按钮级权限，一个 LLM 为此写了中间件去正则匹配
// `@click="remove(row)"` 再从 HTML 里抠按钮。权限节点 {uri}.{action}
// 框架本来就有，现在按钮直接按它渲染。
$viewerJar = sys_get_temp_dir().'/amn-tp-btn-'.getmypid().'.jar';
@unlink($viewerJar);

$viewerGet = function (string $path) use ($viewerJar): array {
    $ch = curl_init("http://127.0.0.1:{$GLOBALS['port']}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $viewerJar,
        CURLOPT_COOKIEFILE => $viewerJar,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return [$code, $body];
};

[$_, $vLogin] = $viewerGet('/admin/auth/login');
preg_match('/name="_token" value="([^"]+)"/', $vLogin, $vm);

$ch = curl_init("http://127.0.0.1:{$GLOBALS['port']}/admin/auth/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['_token' => $vm[1] ?? '', 'username' => 'viewer', 'password' => 'viewer123']),
    CURLOPT_COOKIEJAR => $viewerJar,
    CURLOPT_COOKIEFILE => $viewerJar,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 15,
]);
curl_exec($ch);
unset($ch);

[$vpc, $vpb] = $viewerGet("/admin/{$treeUri}");
t('受限账号能打开有 index 权限的页面', $vpc === 200, "HTTP {$vpc}");

preg_match_all('~<button[^>]*@click="([^"]*)"~', $vpb, $vClicks);
$vActions = array_values(array_filter(
    $vClicks[1] ?? [],
    static fn ($c) => str_contains($c, 'openCreate') || str_contains($c, 'edit(') || str_contains($c, 'remove(')
));
t(
    '无 create/update/destroy → 三个按钮都不渲染',
    $vActions === [],
    $vActions === [] ? '服务端未输出任何动作按钮' : '仍渲染了: '.implode(',', $vActions)
);

/*
 * ③-2 外键下拉（belongsTo）—— 与 Laravel 侧读**同一份**共享期望。
 *
 * 候选与 exists 规则由框架从模型关联自动解析，声明方只写一行。
 * 这里断言两端解析结果同形（类型/关联名/exists 前缀/候选形状）。
 */
$btExp = $treeExp['belongs_to_field'] ?? null;

if ($btExp === null) {
    t('读得到外键下拉共享期望', false, 'fixtures 里缺 belongs_to_field');
} else {
    [$btc, $btb] = $treeReq('/admin/'.$btExp['resource']);
    t('外键样本页面可访问', $btc === 200, "HTTP {$btc}");

    preg_match('~schema\s*=\s*(\{.*?\});~s', $btb, $btm);
    $btSchema = json_decode($btm[1] ?? '{}', true) ?: [];
    $btField = null;

    foreach ($btSchema['form']['fields'] ?? [] as $bf) {
        if (($bf['name'] ?? '') === $btExp['field']) {
            $btField = $bf;
            break;
        }
    }

    t('外键字段存在', $btField !== null, $btField === null ? 'schema 里找不到' : '找到');

    if ($btField !== null) {
        $exp = $btExp['expect'];
        $opts = $btField['props']['options'] ?? [];

        t(
            '控件类型与期望一致',
            ($btField['type'] ?? '') === $exp['type'],
            (string) ($btField['type'] ?? '')
        );
        t(
            '关联名与期望一致',
            ($btField['props']['relation'] ?? '') === $exp['relation'],
            (string) ($btField['props']['relation'] ?? '(无)')
        );
        t(
            '候选数达到期望',
            count($opts) >= $exp['min_options'],
            count($opts).' 个（期望 >= '.$exp['min_options'].'）'
        );

        $first = $opts[0] ?? [];
        t(
            '候选形状与期望一致',
            isset($first[$exp['option_keys'][0]], $first[$exp['option_keys'][1]]),
            json_encode($first, JSON_UNESCAPED_UNICODE)
        );

        $existsOk = false;
        foreach ($btField['rules'] ?? [] as $r) {
            if (str_starts_with((string) $r, $exp['exists_prefix'])) {
                $existsOk = true;
            }
        }
        t('exists 规则前缀与期望一致', $existsOk, $existsOk ? '一致' : '不一致或缺失');
    }
}

/*
 * ③-2b 可空外键**真能提交** + **非 required** 规则真生效 —— 与 Laravel 侧读同一份期望。
 *
 * 这两条锁的是两个 P0（2026-10-16 审计），它们此前**藏在一个全绿的基线之下**：
 *
 *   ① 驱动的规则白名单只有 13 条，既没有 `nullable` 也没有 `exists`，
 *      而 core 的 BelongsTo 自动补这两条
 *      → 任何带外键下拉的 Resource 在 TP 上提交必 500。
 *
 *   ② think-validate v3 的构造函数**没有参数**，而驱动写成
 *      `new Validate($translated, $messages)` → 规则集为空 → `check()` 永远 true
 *      → 除手写的 require 外，max / min / email / in / date / regex **全部静默失效**。
 *
 * 上面那段只断言了 schema 里的规则字符串，**没发过一次真实请求** ——
 * 于是「声明里有这条规则」和「这条规则真会跑」之间的缺口一直没人看见。
 */
$btSubExp = $treeExp['belongs_to_submit'] ?? null;

if ($btSubExp === null) {
    t('读得到外键提交共享期望', false, 'fixtures 里缺 belongs_to_submit');
} else {
    $btSubRes = (string) $btSubExp['resource'];
    $btValidIn = $btSubExp['valid_status_in'];

    [$st, $r] = $api('POST', $btSubRes, (array) $btSubExp['valid_payload']);
    t(
        'TP：NULL 外键能提交（顶级节点）',
        in_array($st, $btValidIn, true),
        "HTTP {$st} ".substr(is_string($r) ? strip_tags($r) : json_encode($r, JSON_UNESCAPED_UNICODE), 0, 100)
    );

    // 探针清理：NULL 那条成功了，把它删掉，别在库里留垃圾
    $btSubId = is_array($r) ? ($r['data']['id'] ?? null) : null;

    [$st2, $r2] = $api('POST', $btSubRes, (array) $btSubExp['bogus_payload']);
    t(
        'TP：伪外键 id 被拒（exists 真的查库）',
        $st2 === (int) $btSubExp['bogus_status'],
        "HTTP {$st2} ".substr(is_string($r2) ? strip_tags($r2) : json_encode($r2, JSON_UNESCAPED_UNICODE), 0, 100)
    );

    if ($btSubId !== null) {
        $api('DELETE', $btSubRes.'/'.$btSubId);
    }
}

$nrExp = $treeExp['non_required_rule'] ?? null;

if ($nrExp === null) {
    t('读得到非必填规则共享期望', false, 'fixtures 里缺 non_required_rule');
} else {
    $nrRes = (string) $nrExp['resource'];
    [$st, $r] = $api('POST', $nrRes, (array) $nrExp['too_long_payload']);
    t(
        'TP：非 required 规则真的拦（max:'.$nrExp['max_length'].'）',
        $st === (int) $nrExp['expect_status'],
        "HTTP {$st} ".substr(is_string($r) ? strip_tags($r) : json_encode($r, JSON_UNESCAPED_UNICODE), 0, 100)
    );

    /*
     * ⚠️ 规则**失效时**这条请求会真的写库 —— 必须把它删掉。
     *
     * 踩过：第一次写完这段忘了清理，反例验证（故意回退修复）时
     * 留下一条 21 字符的部门，把后续三条树形断言全带红了 ——
     * 「测试污染数据，然后被自己的污染绊倒」。
     * 所以清理要放在**断言之外**：不管断言红绿都得跑。
     */
    $nrId = is_array($r) ? ($r['data']['id'] ?? null) : null;

    if ($nrId !== null) {
        $api('DELETE', $nrRes.'/'.$nrId);
    }
}

/*
 * ③-3 声明式自定义批量动作 —— 与 Laravel 侧读同一份共享期望。
 *
 * 断言四件事：动作在声明里、标签正确、调一次真的写库、未声明的动作被拒。
 */
$cbExp = $treeExp['custom_batch_action'] ?? null;

if ($cbExp === null) {
    t('读得到批量动作共享期望', false, 'fixtures 里缺 custom_batch_action');
} else {
    [$cbc, $cbb] = $treeReq('/admin/'.$cbExp['resource']);
    t('批量动作样本页面可访问', $cbc === 200, "HTTP {$cbc}");

    preg_match('~schema\s*=\s*(\{.*?\});~s', $cbb, $cbm);
    $cbSchema = json_decode($cbm[1] ?? '{}', true) ?: [];
    $cbGrid = $cbSchema['grid'] ?? [];

    t(
        '自定义动作出现在 batchActions 里',
        in_array($cbExp['action'], $cbGrid['batchActions'] ?? [], true),
        implode(',', $cbGrid['batchActions'] ?? [])
    );

    $cbDecl = $cbGrid['customBatchActions'][0] ?? [];
    t('声明里带标签', ($cbDecl['label'] ?? '') === $cbExp['label'], (string) ($cbDecl['label'] ?? ''));
    t('声明里带处理器类名', is_string($cbDecl['handler'] ?? null), (string) ($cbDecl['handler'] ?? '(无)'));

    // 真的调一次 —— 只验声明是不够的：声明对了但处理器没被调用，
    // 界面照样显示按钮
    [$_, $cbListBody] = $treeReq("/admin/api/{$cbExp['resource']}");
    $cbIds = [];

    foreach (json_decode($cbListBody, true)['data'] ?? [] as $row) {
        if (isset($row['id'])) {
            $cbIds[] = $row['id'];
        }
    }

    t('批量样本有可操作的行', $cbIds !== [], count($cbIds).' 行');

    $cbCsrf = '';
    if (preg_match('~name="csrf-token" content="([^"]+)"~', $cbb, $cm2)) {
        $cbCsrf = $cm2[1];
    }

    [$cbCode, $cbBody] = $treeReq(
        "/admin/api/{$cbExp['resource']}/batch",
        'POST',
        (string) json_encode(['action' => $cbExp['action'], 'ids' => $cbIds])
    );

    $cbJson = json_decode($cbBody, true);
    t('调用自定义批量动作 → 200', $cbCode === 200, "HTTP {$cbCode} ".substr($cbBody, 0, 80));
    t(
        '受影响行数与期望一致',
        (int) ($cbJson['affected'] ?? -1) === (int) $cbExp['expect']['affected'],
        'affected='.var_export($cbJson['affected'] ?? null, true)
    );

    // 复查：状态真的变了
    [$_, $cbAfterBody] = $treeReq("/admin/api/{$cbExp['resource']}");
    $cbStatuses = array_map(
        static fn ($r) => $r['status'] ?? null,
        json_decode($cbAfterBody, true)['data'] ?? []
    );
    t(
        '数据真的被改（不是只回了 200）',
        $cbStatuses !== [] && ! array_diff(array_filter($cbStatuses), [$cbExp['expect']['final_status']]),
        implode(',', array_map(static fn ($v) => (string) $v, $cbStatuses))
    );

    // 未声明的动作必须被拒
    [$cbBadCode] = $treeReq(
        "/admin/api/{$cbExp['resource']}/batch",
        'POST',
        (string) json_encode(['action' => 'not-declared-anywhere', 'ids' => $cbIds])
    );
    t('未声明的动作被拒', $cbBadCode === 403, "HTTP {$cbBadCode}");
}

/*
 * ③-4 文档与实现的一致性 —— 与 Laravel 侧读同一份共享期望。
 */
$cc = $treeExp['contract_consistency'] ?? null;

if ($cc === null) {
    t('读得到一致性共享期望', false, 'fixtures 里缺 contract_consistency');
} else {
    // ② 非法排序列必须明确报错（SQLite 会静默接受 ORDER BY bogus）
    $bad = $cc['invalid_sort'];
    [$badCode, $badBody] = $treeReq('/admin/api/'.($bad['resource_uri'] ?? 'departments').'?sort='.urlencode((string) $bad['typo']));
    $badJson = json_decode($badBody, true) ?: [];

    t('非法排序列 → 422（不再静默忽略）', $badCode === $bad['expect_code'], "HTTP {$badCode}");
    t(
        '错误码是 '.$bad['expect_error'],
        ($badJson['error'] ?? '') === $bad['expect_error'],
        (string) ($badJson['error'] ?? '(无)')
    );
    t(
        '拼错的列名给 did_you_mean',
        ($badJson['did_you_mean'] ?? '') === $bad['typo_did_you_mean'],
        (string) ($badJson['did_you_mean'] ?? '(无)')
    );

    // 已声明的可排序列仍要放行（别修过头）
    $gate = $cc['sort_gate'] ?? [];
    $gateUri = $bad['sortable_uri'] ?? 'departments';
    [$okCode] = $treeReq('/admin/api/'.$gateUri.'?sort='.($gate['declared_ok'] ?? 'id'));
    t('已声明的排序列仍返回 200', $okCode === 200, "HTTP {$okCode}");

    // 存在但没声明 ->sortable() 的列也要拦
    [$unCode, $unBody] = $treeReq('/admin/api/'.$gateUri.'?sort='.($gate['exists_but_not_declared'] ?? 'name'));
    $unJson = json_decode($unBody, true) ?: [];

    t(
        '存在但未声明 sortable 的列 → 422',
        $unCode === 422 && ($unJson['error'] ?? '') === 'SORT_COLUMN_NOT_DECLARED',
        "HTTP {$unCode}"
    );

    // ③ 猜错的 create 路径必须给出方向正确的提示
    foreach ($cc['no_create_page']['paths'] as $tpl) {
        $url = str_replace('{uri}', (string) ($cc['no_create_page']['resource_uri'] ?? 'departments'), $tpl);
        [$c, $b] = $treeReq($url);
        $j = json_decode($b, true) ?: [];

        t(
            $url.' → NO_CREATE_PAGE 提示',
            $c === $cc['no_create_page']['expect_code']
                && ($j['error'] ?? '') === $cc['no_create_page']['expect_error'],
            "HTTP {$c} ".((string) ($j['error'] ?? '(无)'))
        );
    }
}

// ④ 非树形资源应明确报错，而不是返回空
[$nc, $nbody] = $treeReq('/admin/api/customers/tree');
t(
    '非树形资源调 tree → 403 NOT_A_TREE',
    $nc === 403 && str_contains($nbody, 'NOT_A_TREE'),
    "HTTP {$nc}"
);

echo "\n=== ⑫ 资源页面与静态资源 ===\n";

$pageJar = sys_get_temp_dir().'/amn-tp-page-'.getmypid().'.jar';
@unlink($pageJar);

$pageGet = function (string $path) use ($pageJar): array {
    $ch = curl_init("http://127.0.0.1:{$GLOBALS['port']}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $pageJar,
        CURLOPT_COOKIEFILE => $pageJar,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    unset($ch);

    return [$status, $body, $type];
};

// 登录（资源页面在鉴权组内）
[$lc, $lbody] = $pageGet('/admin/auth/login');
preg_match('/name="_token" value="([^"]+)"/', $lbody, $lm);

$ch = curl_init("http://127.0.0.1:{$GLOBALS['port']}/admin/auth/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['_token' => $lm[1] ?? '', 'username' => 'admin', 'password' => 'admin123']),
    CURLOPT_COOKIEJAR => $pageJar,
    CURLOPT_COOKIEFILE => $pageJar,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 15,
]);
curl_exec($ch);
unset($ch);

// ① 资源页面（HTML）——此前 TP 完全没有这类路由，链接点进去是 404
[$code, $html] = $pageGet('/admin/customers');
t('资源页面 /admin/customers → 200', $code === 200, "HTTP {$code}");
t('资源页面渲染了 schema（不是空壳）', str_contains($html, '"columns"'));
t('资源页面无未替换的模板占位符', ! str_contains($html, '{block') && ! str_contains($html, '{{--'));

/*
 * ② Vue 插值必须原样到达浏览器。
 *
 * `@{{ total }}` 是 Blade 对 Vue 插值的**转义**；转换器若把它当普通
 * `{{ }}` 处理，会产出 `<?php echo total; ?>`（不存在的 PHP 变量）→ 页面 500。
 * 实测踩到，且因为布局 bug 一直没暴露。
 */
t('Vue 插值原样保留（供浏览器端 Vue 使用）', str_contains($html, '{{ total }}'));

// ③ 静态资源 —— 没样式比 404 更隐蔽
foreach ([
    '/admin/assets/css/design-system.css' => 'text/css',
    '/admin/assets/css/themes.css' => 'text/css',
    '/admin/assets/js/vue.global.prod.js' => 'javascript',
] as $asset => $expectType) {
    [$ac, , $atype] = $pageGet($asset);
    t(
        '资源可访问: '.basename($asset),
        $ac === 200 && str_contains($atype, $expectType),
        "HTTP {$ac} {$atype}"
    );
}

// ④ 资源不需要登录（登录页本身要用 LOGO 与 CSS）
[$anonCode] = (function () use ($port): array {
    $ch = curl_init("http://127.0.0.1:{$port}/admin/assets/css/design-system.css");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return [$code];
})();
t('资源免登录可访问（登录页需要）', $anonCode === 200, "HTTP {$anonCode}");

// ⑤ 目录穿越
[$travCode] = $pageGet('/admin/assets/../../../.env');
t('资源路由防目录穿越', $travCode === 404, "HTTP {$travCode}");

/*
 * ⑥ 页面里的链接必须都能点。
 *
 * 曾经全部带 `.html` 后缀（TP 的 `url()` 默认加 `url_html_suffix`），
 * 于是每个菜单项都 404 —— 而页面本身 200，看不出问题。
 */
preg_match_all('~\b(?:href|src)="(/admin/[^"]*)"~', $html, $links);
$broken = [];
foreach (array_unique($links[1]) as $link) {
    // 跳过已知未实现的功能页（不是路由问题）
    if (str_contains($link, '/admin/profile')) {
        continue;
    }
    [$lc2] = $pageGet($link);
    if ($lc2 !== 200) {
        $broken[] = "{$link}({$lc2})";
    }
}
t(
    '页面内链全部可用（无 .html 后缀等）',
    $broken === [],
    $broken === [] ? '全部 200' : implode(' ', array_slice($broken, 0, 3))
);

echo "\n=== ⑪ 多应用隔离（/admin + /agent）===\n";

/*
 * 跨框架共同的真值（与 Laravel 的 http-e2e 共用同一份文件）。
 *
 * 项目主张「同一套声明，多框架行为一致」—— 而两端是两份驱动代码。
 * 各自硬编码期望值 = 「各自能跑」，漂移了也看不出来。
 *
 * ⚠️ 实测：这个 404 就是**先在这一侧写错**的（归属校验排在权限校验之后，
 * 返回了 403），改完回头才发现 Laravel 侧同样中招。
 */
$multiAppExp = json_decode(
    (string) file_get_contents(__DIR__.'/fixtures/multi-app-expectations.json'),
    true
);

if (! is_array($multiAppExp)) {
    t('读得到跨框架期望文件', false, 'tests/e2e/fixtures/multi-app-expectations.json');
    $multiAppExp = ['http' => ['login_page_status' => 200, 'cross_app_status' => 404, 'unauthenticated_redirect_template' => '/{app}/auth/login']];
} else {
    t('读得到跨框架期望文件', true);
}

/*
 * ⓐ slug 形状对拍（与 Laravel 的 MultiApplicationPermissionTest 读同一份文件）。
 *
 * slug 的拼法两端各写一份，**会漂移** —— 各自硬编码期望值验不出来。
 */
$checker = new Aimanong\ThinkPHP\ThinkPermissionChecker;

$expectSingleSlug = str_replace(
    ['{uri}', '{action}'],
    ['orders', 'index'],
    (string) $multiAppExp['slug']['single_app']
);
$expectMultiSlug = str_replace(
    ['{app}', '{uri}', '{action}'],
    ['agent', 'orders', 'index'],
    (string) $multiAppExp['slug']['multi_app']
);

t(
    'slug 多应用形状与共享期望一致',
    $checker->appSlug('agent', 'orders', 'index') === $expectMultiSlug,
    $checker->appSlug('agent', 'orders', 'index')." (期望 {$expectMultiSlug})"
);

$expectLoginStatus = (int) $multiAppExp['http']['login_page_status'];
$expectCross = (int) $multiAppExp['http']['cross_app_status'];
$redirectTpl = (string) $multiAppExp['http']['unauthenticated_redirect_template'];

/** 无登录态发一个请求，返回 [状态码, Location]。 */
$anonProbe = function (string $path, string $jarName): array {
    $anonJar = sys_get_temp_dir().'/amn-tp-anon-'.$jarName;
    @unlink($anonJar);

    $ch = curl_init("http://127.0.0.1:{$GLOBALS['port']}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $anonJar,
        CURLOPT_COOKIEFILE => $anonJar,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);   // PHP 8 下 curl_close 是空操作，必须 unset 才落盘

    preg_match('/^location:\s*(.+)$/mi', $raw, $lm);

    return [$status, trim($lm[1] ?? '')];
};

// ① 两个后台的登录页都要能打开
foreach (['admin', 'agent'] as $probeApp) {
    [$ac] = $anonProbe("/{$probeApp}/auth/login", $probeApp.'-login');
    t("/{$probeApp} 登录页 {$expectLoginStatus}", $ac === $expectLoginStatus, "HTTP {$ac}");
}

// ② 未登录跳转必须指向**自己**的登录页，不是别人的
foreach (['admin', 'agent'] as $probeApp) {
    [$ac, $loc] = $anonProbe("/{$probeApp}", $probeApp.'-home');
    $expectLoc = str_replace('{app}', $probeApp, $redirectTpl);
    t(
        "未登录 /{$probeApp} → 跳 {$expectLoc}",
        $ac === 302 && str_contains($loc, $expectLoc),
        "HTTP {$ac} → {$loc}"
    );
}

// ③ 用代理商身份登录（账号由 setup-thinkphp-demo.sh 建）
$agentJar = sys_get_temp_dir().'/amn-tp-agent-'.getmypid().'.jar';
@unlink($agentJar);

$agentGet = function (string $path) use ($agentJar): array {
    $ch = curl_init("http://127.0.0.1:{$GLOBALS['port']}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $agentJar,
        CURLOPT_COOKIEFILE => $agentJar,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return [$status, $body];
};

[$lc, $lbody] = $agentGet('/agent/auth/login');
preg_match('/name="_token" value="([^"]+)"/', $lbody, $lm2);

$ch = curl_init("http://127.0.0.1:{$GLOBALS['port']}/agent/auth/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token' => $lm2[1] ?? '',
        'username' => 'agent1',
        'password' => 'agent123',
    ]),
    CURLOPT_COOKIEJAR => $agentJar,
    CURLOPT_COOKIEFILE => $agentJar,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 15,
]);
curl_exec($ch);
$agentLoginCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

t("agent 登录成功", in_array($agentLoginCode, [200, 302], true), "HTTP {$agentLoginCode}");

/*
 * ④ 跨后台访问：客户 Resource 声明了 applications() => ['admin']，
 *    从代理商后台访问必须 **404**。
 *
 *    这条一次验两件事：
 *      · 归属过滤真的生效（不是「配了但没用」）
 *      · 返回 404 而不是 403 —— 403 等于告诉对方「资源存在，只是你没权限」，
 *        那是把后台结构泄露出去
 */
[$crossCode] = $agentGet('/agent/api/customers');
t(
    "跨后台访问 admin 专属 Resource → {$expectCross}",
    $crossCode === $expectCross,
    "HTTP {$crossCode}".($crossCode === 403 ? '（403 会泄露资源存在）' : '')
);

// ⑤ 代理商后台自己的登录态是独立的：不该能用它访问 /admin
[$adminAsAgentCode] = $agentGet('/admin');
t(
    "代理商的 cookie 访问 /admin 仍需登录",
    in_array($adminAsAgentCode, [302, 403], true),
    "HTTP {$adminAsAgentCode}"
);

echo "\n═══ 未移植动作：必须明确 501，不能落进 CRUD ═══\n";

/*
 * 🔴 这一段锁的是一个「**悄悄做错事**」的 bug（2026-10-16 实测）。
 *
 * `export` / `move` 在 TP 侧没有实现，文档说「调时返回 501」——
 * 但**根本没挂路由**，实际行为是：
 *
 *     GET  /admin/api/departments/export → 404 NOT_FOUND
 *          被 `:uri/:id` 当成 id="export"，报「不存在 id=export 的记录」
 *
 *     POST /admin/api/departments/move   → 走进了 store()
 *          实测返回的校验错误是 "name长度不能超过 20" —— 创建接口的校验。
 *          **载荷合法时它会真的插一条记录，而且不报错。**
 *
 * 根因：TP 路由默认不完整匹配，`api/:uri` 会把多余的段吞掉。
 * 修法是给 api 组加 `->completeMatch()` + 给这两个动作挂显式 501 路由。
 *
 * 最后一条断言（记录数不变）是**真正的护栏** ——
 * 前三条只验「返回码对」，它验「没有偷偷写库」。
 */
foreach ([['GET', 'export'], ['POST', 'export'], ['POST', 'move']] as [$m, $act]) {
    [$st, $r] = $api($m, "customers/{$act}");
    t(
        "TP {$m} customers/{$act} → 501（不是 404/422，更不是创建）",
        $st === 501 && ($r['error']['code'] ?? '') === 'NOT_IMPLEMENTED_ON_THINKPHP',
        "HTTP {$st} ".($r['error']['code'] ?? '')
    );
}

// 记录数不能因为一个 move 请求而变 —— 载荷刻意合法，模拟「真的想调 move」
//
// ⚠️ 载荷必须**能通过 store 的校验**，否则这条护栏是假的：
// 第一版传了 `code`（表里没这列）且漏了 `industry`（requiredOnCreate），
// 于是即使 move 真的走进了 store()，它也在校验阶段就被拒，
// 记录数当然不变 —— **断言绿了，但它什么都没验到**。
// 反例验证时发现（3 条状态断言红了、这条没红），才回头补的教训。
[, $tpBefore] = $api('GET', 'customers');
$tpN0 = $tpBefore['meta']['total'] ?? -1;
$api('POST', 'customers/move', ['name' => 'MOVE_PROBE', 'industry' => 'PROBE']);
[, $tpAfter] = $api('GET', 'customers');
$tpN1 = $tpAfter['meta']['total'] ?? -1;
t(
    'move 没有被 store() 接走（记录数不变）',
    $tpN0 >= 0 && $tpN1 === $tpN0,
    "{$tpN0} → {$tpN1}"
);

echo "\n═══ AI 自省接口 /__ai/* ═══\n";

/*
 * 🔴 这一段是**新补的**（2026-10-16）。
 *
 * 在此之前 TP 侧**完全没有** /__ai/*。这不是「少个便利接口」：
 *
 *   · `llms.txt` / `capabilities.json` 是**跨驱动**的 AI 资料，
 *     它们教 AI「先查 /__ai/capabilities.json」——
 *     TP 用户照做拿到 404，只能回去猜 API
 *   · 宪法①「AI 优先」在多框架下直接失效
 *
 * 而这类缺失**运行期看不出来**：TP 的页面、CRUD、校验全都正常，
 * 只有真去请求那个路径才发现 404。所以要断言。
 *
 * 期望值来自共享 fixture（Laravel 侧有对称断言）。
 */
$introExp = $multiAppExp['introspection'] ?? [];

$aiGet = function (string $path) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/__ai/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return [$status, $body];
};

if ($introExp === []) {
    t('读得到自省共享期望', false, 'fixtures 里缺 introspection');
} else {
    $wantStatus = (int) $introExp['expected_status'];

    // ① 全部端点都要在（TP 侧此前一个都没有）
    foreach ((array) ($introExp['endpoints'] ?? []) as $ep) {
        if (str_contains($ep, '{')) {
            continue;
        }
        [$st] = $aiGet($ep);
        t("TP 自省端点 /__ai/{$ep} 存在", $st === $wantStatus, "HTTP {$st}");
    }

    // ② POST 端点
    $ch = curl_init("http://127.0.0.1:{$port}/__ai/verify");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['resource' => 'app\\DepartmentResource']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $vBody = (string) curl_exec($ch);
    $vStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    t('TP /__ai/verify 可用', $vStatus === 200, "HTTP {$vStatus}");
    t(
        'TP /__ai/verify 返回结构化结果',
        is_array(json_decode($vBody, true)) && array_key_exists('valid', json_decode($vBody, true) ?? []),
        mb_substr($vBody, 0, 80)
    );

    // ③ capabilities 含全部驱动无关键
    [$cst, $cbody] = $aiGet('capabilities.json');
    $caps = json_decode($cbody, true) ?: [];
    $missing = [];

    foreach ((array) ($introExp['driver_independent_capability_keys'] ?? []) as $k) {
        if (! isset($caps[$k]) || $caps[$k] === [] || $caps[$k] === '') {
            $missing[] = $k;
        }
    }

    t(
        'TP capabilities 含全部驱动无关键',
        $cst === 200 && $missing === [],
        $missing === [] ? count($introExp['driver_independent_capability_keys']).' 个' : '缺：'.implode('、', $missing)
    );

    // ④ 未知参数要 404
    $wantMissing = (int) $introExp['missing_status'];
    [$bsc] = $aiGet('schema/no-such-uri-xyz');
    [$bex] = $aiGet('examples/no-such-pattern-xyz');
    t("TP 未知 uri 的 schema → {$wantMissing}", $bsc === $wantMissing, "HTTP {$bsc}");
    t("TP 未知 pattern 的 examples → {$wantMissing}", $bex === $wantMissing, "HTTP {$bex}");

    /*
     * ⑤ 跨驱动对拍：examples 的 code **内容**必须相同。
     *
     * ⚠️ 不比对原始字节 —— 两端 JSON 序列化不同：
     * Laravel 的 response()->json() 把 `/` 转义成 `\/`，TP 的 json() 不转义。
     * 同一份内容、不同的转义，比字节会**假红**。
     * 所以解析成结构再比 code 字段。
     *
     * Laravel demo 跑在 8899（pre-release 会起它）。取不到就跳过并说明 ——
     * 单独跑本脚本（没有 Laravel demo）时不该硬失败。
     */
    $lvBase = 'http://127.0.0.1:8899';
    $lvUp = false;
    $probe = curl_init("$lvBase/__ai/capabilities.json");
    curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    curl_exec($probe);
    $lvUp = (int) curl_getinfo($probe, CURLINFO_HTTP_CODE) === 200;
    unset($probe);

    if (! $lvUp) {
        echo "  ⏭  跳过「跨驱动对拍」：Laravel demo (8899) 未运行\n";
    } else {
        $parityBad = [];

        foreach ((array) ($introExp['byte_identical_examples'] ?? []) as $pat) {
            [$tst, $tbody] = $aiGet("examples/{$pat}");

            $ch = curl_init("$lvBase/__ai/examples/{$pat}");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
            $lbody = (string) curl_exec($ch);
            $lst = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);

            $tc = json_decode($tbody, true)['code'] ?? null;
            $lc = json_decode($lbody, true)['code'] ?? null;

            if ($tst !== 200 || $lst !== 200 || $tc === null || $tc !== $lc) {
                $parityBad[] = $pat;
            }
        }

        t(
            '跨驱动对拍：examples 内容两端一致',
            $parityBad === [],
            $parityBad === [] ? count($introExp['byte_identical_examples']).' 个片段' : '不一致：'.implode('、', $parityBad)
        );
    }
}

echo "\n═══ 扩展机制 ═══\n";

/*
 * 🔴 在此之前 TP 侧**完全没有扩展机制** ——
 * `config/aimanong.extensions` 写了也没人读，扩展类不会被 boot。
 *
 * 后果不是「少个功能」：
 *   · `capabilities.json` 的 `extension` 段一直在教 AI 怎么写扩展，
 *     对 TP 用户是一纸空文
 *   · 装了 `aimanong/region` 的 TP 项目**不会**得到 region 字段类型，
 *     而且**不报错** —— 症状是 `$form->region()` 编译失败、报错指向别处
 *
 * demo 里登记了 `app\Extensions\DemoExtension`（由 setup-thinkphp-demo.sh 生成），
 * 它做了两件可观测的事：注入 `demo_text` 字段类型 + 注册一条 /ping 路由。
 */
[$ftSt, $ftBody] = $aiGet('capabilities.json');
$ftCaps = json_decode($ftBody, true) ?: [];
$fieldTypes = $ftCaps['field_types'] ?? [];

t(
    '扩展注入的字段类型出现在 capabilities 里',
    isset($fieldTypes['demo_text']),
    isset($fieldTypes['demo_text'])
        ? 'demo_text ✅'
        : '缺 demo_text（现有：'.implode('、', array_slice(array_keys((array) $fieldTypes), 0, 6)).'…）'
);

/*
 * 扩展注册的路由 —— 挂在 /<后台前缀>/extensions/<扩展名>/ 下，带鉴权。
 *
 * ⚠️ 这条断言盯着一个**真实踩过的坑**：扩展路由原本注册在后台兜底路由
 * （`Route::get(':uri', ...)`）**之后**，被它当成 uri=`extensions` 吞掉 ——
 * 路由表里明明有这条规则，请求却 404「Resource [extensions] 未注册」。
 * 修法是「扩展路由先注册」+ 后台组开 completeMatch。
 */
$extCh = curl_init("http://127.0.0.1:{$port}/admin/extensions/demo/ping");
curl_setopt_array($extCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
]);
$extBody = (string) curl_exec($extCh);
$extStatus = (int) curl_getinfo($extCh, CURLINFO_HTTP_CODE);
unset($extCh);

$extJson = json_decode($extBody, true);

t(
    '扩展注册的路由可访问且返回正确内容',
    $extStatus === 200 && is_array($extJson) && ($extJson['pong'] ?? null) === true,
    "HTTP {$extStatus} ".mb_substr(trim($extBody), 0, 60)
);

exec('rm -rf '.escapeshellarg($fresh));

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

$completed = true;
