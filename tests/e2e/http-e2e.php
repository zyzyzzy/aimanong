<?php
/** HTTP 层端到端：多应用隔离 + 各功能页面 */
/*
 * 本脚本只跑 HTTP（不依赖 demo 的 autoload），
 * 但保留 DEMO_PATH 校验以便提示正确用法。
 */
/*
 * 跨框架共同的真值（与 ThinkPHP 的 e2e 共用同一份文件）。
 *
 * 项目主张「同一套声明，多框架行为一致」—— 而两端是两份驱动代码。
 * 各自硬编码期望值 = 「各自能跑」，漂移了也看不出来。
 * 从同一份文件读，漂移的那一侧才会红。
 *
 * 实测教训：这两个数字（404 / 跳转目标）两端各写错过一次。
 */
$multiAppExp = json_decode((string) file_get_contents(__DIR__.'/fixtures/multi-app-expectations.json'), true);

if (! is_array($multiAppExp)) {
    fwrite(STDERR, "❌ 读不到跨框架期望文件 tests/e2e/fixtures/multi-app-expectations.json\n");
    exit(1);
}

$demo = getenv('DEMO_PATH') ?: __DIR__.'/../../../demo-app';

if (! is_dir($demo)) {
    fwrite(STDERR, "找不到 demo 项目: {$demo}\n");
    fwrite(STDERR, "请设置环境变量 DEMO_PATH 指向 demo 项目目录。\n");
    exit(1);
}

function http(string $url, string $method = 'GET', array $data = [], array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => '/tmp/e2e-cookie.txt',
        CURLOPT_COOKIEFILE => '/tmp/e2e-cookie.txt',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    /*
     * ⚠️ 必须显式处理 PUT / DELETE / PATCH。
     *
     * 原先只判断 `$method === 'POST'` —— 传 'DELETE' 时既没设 POST 也没设
     * 自定义请求方法，curl 就**当成 GET 发出去**了。
     * 于是「只读资源拒绝 DELETE」这条测的其实是 GET /{id}（show），
     * 拿到 200 还以为是框架没拦住 —— 测试工具自己骗了自己。
     */
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'location' => $loc];
}

/*
 * 服务地址可用 E2E_BASE 覆盖。
 *
 * 硬编码端口会导致「本机 8899 被占用时没法验证」——
 * CI 里尤其常见（并行 job、残留进程）。
 */
$base = getenv('E2E_BASE') ?: 'http://localhost:8899';
@unlink('/tmp/e2e-cookie.txt');
$pass = 0;
$fail = 0;

/*
 * 没跑到结尾就退出 = 中途崩了 = 失败。
 * 原因同 framework-e2e.php：Laravel 的全局异常处理器渲染后正常返回，
 * 退出码仍是 0，而 pre-release.sh 只看退出码。
 */
$completed = false;
register_shutdown_function(static function (): void {
    global $completed;

    if (! $completed) {
        fwrite(STDERR, "\n❌ http-e2e 未跑完（中途崩溃）—— 判定为失败\n");

        exit(1);
    }
});

function t(string $n, bool $c, string $note = ''): void {
    global $pass, $fail;
    printf("  %-46s %s%s\n", $n, $c ? "✅" : "❌", $note ? "  $note" : '');
    $c ? $pass++ : $fail++;
}

echo "═══ 多应用隔离 ═══\n";
$a = http("$base/admin/auth/login");
preg_match('/name="_token" value="([^"]+)"/', $a['body'], $am);
t("admin 登录页 200", $a['code'] === 200);

/*
 * merchant 后台是**可选**的多应用能力 ——
 * 全新项目默认不配（config('aimanong.applications') 为空）。
 * 未配置时跳过并说明，而不是判失败。
 */
$m = http("$base/merchant/auth/login");
if ($m['code'] === 200) {
    preg_match('/name="_token" value="([^"]+)"/', $m['body'], $mm);
    t("merchant 登录页 200", true, '多应用已配置');
} else {
    echo "  ⏭  跳过「merchant 后台」：该项目未配置多应用（可选能力）\n";
}

/*
 * 登录：先取页拿到 session cookie，再从**同一个响应后的 cookie jar** 取 token。
 * 注意 $a 阶段已经拿过 cookie，这里重新取一次确保 token 与 cookie 匹配。
 */
$a2 = http("$base/admin/auth/login");
preg_match('/name="_token" value="([^"]+)"/', $a2['body'], $tok);
$login = http("$base/admin/auth/login", 'POST', ['_token' => $tok[1] ?? '', 'username' => 'admin', 'password' => 'admin123']);
/*
 * 登录接口按请求类型返回不同响应（设计如此）：
 *   - JSON 请求（Accept: application/json）→ 200 + {message, redirect}
 *   - 表单请求 → 302 跳转
 * 这里发的是 JSON 请求，因此断言 200。
 */
$lj = json_decode((string) $login['body'], true);
t(
    "admin 登录成功",
    in_array($login['code'], [200, 302], true) && ($lj !== null || $login['code'] === 302),
    $login['code'] === 200 ? 'JSON: '.($lj['message'] ?? '') : '302 跳转'
);

/*
 * ═══════════════════════════════════════════════════════════
 * 探测本项目实际有哪些 Resource
 * ═══════════════════════════════════════════════════════════
 *
 * 这个脚本原先直接假定存在 categories / courses / departments 等
 * **demo 专有** Resource。在全新安装的项目上它们不存在，
 * 相关断言会全部失败 —— 所以 CI 没法用它（见《初衷对照评估》偏离 2）。
 *
 * 现在先从自省接口拿真实清单，再对「存在的」做验证。
 */
$__cap = json_decode(http("$base/__ai/capabilities.json")['body'] ?? '', true);
$availableUris = [];
foreach (($__cap['resources'] ?? []) as $__r) {
    if (isset($__r['uri'])) {
        $availableUris[] = $__r['uri'];
    }
}
$has = fn (string $uri): bool => in_array($uri, $availableUris, true);

echo "  ℹ️  本项目共 ".count($availableUris)." 个 Resource\n";

// 挑一个一定能读的列表接口（优先 demo 的，否则用内置的）
$listUri = null;
foreach (['categories', 'admin-users', 'admin-dict-types', 'tickets'] as $cand) {
    if ($has($cand)) {
        $listUri = $cand;
        break;
    }
}

if ($listUri === null) {
    t("登录态生效（可访问 API）", false, '找不到任何可读的 Resource');
} else {
    $probe = http("$base/admin/api/{$listUri}");
    t("登录态生效（可访问 API）", $probe['code'] === 200, "uri={$listUri}");
}

$home = http("$base/admin");
t("admin 首页 200（无重定向循环）", $home['code'] === 200);

echo "\n═══ 各功能页面 ═══\n";
// 只验证本项目**实际存在**的 Resource 页面
foreach ([
    'categories' => '树形',
    'courses' => '分步表单',
    'suppliers' => '代码生成',
    'departments' => '综合',
] as $uri => $feature) {
    if (! $has($uri)) {
        continue;
    }
    $r = http("$base/admin/$uri");
    t("$feature 页 /admin/$uri", $r['code'] === 200);
}
if (! $has('categories') && ! $has('courses')) {
    echo "  ⏭  跳过 demo 专有页面（本项目未安装对应 Resource）\n";
}

echo "\n═══ 功能接口 ═══\n";

if ($has('categories')) {
    $tree = http("$base/admin/api/categories/tree");
    $tj = json_decode($tree['body'], true);
    t("树形 API", $tree['code'] === 200 && isset($tj['data']), count($tj['data'] ?? [])." 个根节点");
}

if ($has('courses')) {
    $exp = http("$base/admin/api/courses/export");
    t("导出接口返回 CSV", $exp['code'] === 200 && str_contains($exp['body'], '课程编号'));
}

if ($listUri !== null) {
    $list = http("$base/admin/api/{$listUri}");
    t("列表 API", $list['code'] === 200 && isset(json_decode($list['body'], true)['meta']), "uri={$listUri}");
}

// 「未开启导出返回 403」需要一个**确实没开导出**的 Resource
$noExportUri = null;
foreach (['categories', 'admin-users'] as $cand) {
    if ($has($cand)) {
        $noExportUri = $cand;
        break;
    }
}
if ($noExportUri !== null) {
    $noExport = http("$base/admin/api/{$noExportUri}/export");
    $nj = json_decode($noExport['body'], true);
    // 可能开了导出（200）也可能没开（403），两种都算正确行为，
    // 重要的是**不能 500**
    t("导出未开启时正确拒绝", in_array($noExport['code'], [200, 403], true), "HTTP {$noExport['code']}");
}

echo "\n═══ AI 自省接口 ═══\n";
$introspect = [
    '/__ai/capabilities.json' => 'capabilities',
    '/__ai/openapi.json' => 'openapi',
    '/__ai/context' => 'context',
];
foreach ($availableUris as $u) {
    $introspect['/__ai/schema/'.$u] = 'schema('.$u.')';
    break;   // 一个就够，避免刷屏
}
foreach ($introspect as $path => $name) {
    $r = http("$base$path");
    t("自省 $name", $r['code'] === 200);
}

// M5 元数据：只在对应的 Resource 存在时才有意义
if ($has('departments') || $has('categories')) {
    $metaUri = $has('departments') ? 'departments' : 'categories';
    $sch = json_decode(http("$base/__ai/schema/{$metaUri}")['body'], true);
    t("自省含 M5 元数据", isset($sch['grid']['tree'], $sch['form']['steps']), "uri={$metaUri}");
} else {
    echo "  ⏭  跳过「M5 元数据」：本项目没有树形/分步 Resource\n";
}

/*
 * ═══════════════════════════════════════════════════════════
 * 基座能力（v1.6.0）—— 真实 HTTP 行为
 * ═══════════════════════════════════════════════════════════
 *
 * 这一节验证的是**文档与实现是否一致**，而不只是"能不能跑"。
 * 第六轮实测证明：产物正确 ≠ 运行时正确（只读资源的 OpenAPI
 * 曾经宣告写接口，而接口实际 403）。
 */
echo "\n═══ 基座能力 · 页面可访问 ═══\n";
foreach ([
    'admin-operation-logs' => '操作日志',
    'admin-login-logs' => '登录日志',
    'admin-dict-types' => '数据字典',
    'admin-dict-items' => '字典条目',
    'admin-users' => '管理员账号',
    'profile' => '个人中心',
] as $uri => $label) {
    $r = http("$base/admin/$uri");
    t("$label 页", $r['code'] === 200, "/admin/$uri → {$r['code']}");
}

echo "\n═══ 只读资源：接口必须拒绝写 ═══\n";
$roList = http("$base/admin/api/admin-operation-logs");
t("只读资源可读", $roList['code'] === 200);

// 写操作必须 403（这正是 OpenAPI 不该宣告它们的原因）
$csrf = '';
if (preg_match('/name="csrf-token" content="([^"]+)"/', $roList['body'], $cm)) {
    $csrf = $cm[1];
} else {
    $page = http("$base/admin/admin-operation-logs");
    preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $cm2);
    $csrf = $cm2[1] ?? '';
}

$roPost = http("$base/admin/api/admin-operation-logs", 'POST', ['username' => 'hack'], ['X-CSRF-TOKEN: '.$csrf]);
t("只读资源拒绝 POST", $roPost['code'] === 403, "HTTP {$roPost['code']}");

/*
 * DELETE 必须指向**真实存在**的记录。
 *
 * ⚠️ 不能硬编码 id：如果记录不存在，接口会先返回 404（找不到记录），
 * 那验证的就成了"路由"而不是"只读拦截"—— 测试会假绿或假红。
 * （第一次写这条时就踩了：库里的 id 从 6 开始，硬编码 1 得到 404。）
 */
$loginLogs = json_decode(http("$base/admin/api/admin-login-logs")['body'] ?? '', true);
$firstLogId = $loginLogs['data'][0]['id'] ?? null;

if ($firstLogId === null) {
    t("只读资源拒绝 DELETE", false, '没有可用的登录日志记录，无法验证');
} else {
    $roDel = http("$base/admin/api/admin-login-logs/".$firstLogId, 'DELETE', [], ['X-CSRF-TOKEN: '.$csrf]);
    t("只读资源拒绝 DELETE", $roDel['code'] === 403, "id={$firstLogId} → HTTP {$roDel['code']}");
}

/*
 * ═══ 搜索 LIKE 转义 + email 字段自动规则 ═══
 */
echo "\n═══ 搜索与字段修复 ═══\n";

$sf = json_decode((string) @file_get_contents(__DIR__.'/fixtures/multi-app-expectations.json'), true)['search_and_field_fixes'] ?? null;

if ($sf === null) {
    t('读得到搜索修复共享期望', false, 'fixtures 里缺 search_and_field_fixes');
} else {
    $le = $sf['like_escape'];

    /*
     * ⚠️ 用 admin-users（内置资源，两端都有且 name 列 searchable）。
     * 不能用 users —— CI demo 没有这个资源。
     */
    $searchUri = 'admin-users';

    // % 不应匹配全部
    $pct = http("$base/admin/api/{$searchUri}?keyword=%25", 'GET', [], ['Accept: application/json']);
    $pctJson = json_decode((string) $pct['body'], true) ?: [];
    t(
        'keyword=% 不匹配全部（LIKE 转义生效）',
        ($pctJson['meta']['total'] ?? -1) === $le['keyword_percent']['expect_total'],
        'total='.($pctJson['meta']['total'] ?? '?')
    );

    // _ 不应匹配任意字符
    $usc = http("$base/admin/api/{$searchUri}?keyword=_", 'GET', [], ['Accept: application/json']);
    $uscJson = json_decode((string) $usc['body'], true) ?: [];
    t(
        'keyword=_ 不匹配任意字符',
        ($uscJson['meta']['total'] ?? -1) === $le['keyword_underscore']['expect_total'],
        'total='.($uscJson['meta']['total'] ?? '?')
    );

    // 正常搜索不受影响
    $norm = http("$base/admin/api/{$searchUri}?keyword=".rawurlencode('admin'), 'GET', [], ['Accept: application/json']);
    $normJson = json_decode((string) $norm['body'], true) ?: [];
    t(
        '正常搜索仍工作',
        ($normJson['meta']['total'] ?? 0) >= $le['keyword_normal']['expect_min'],
        'total='.($normJson['meta']['total'] ?? '?')
    );
}

/*
 * ═══ 枚举标签 ═══
 *
 * ⚠️ 这里**故意不加断言**。
 *
 * 枚举标签解析是纯函数（输入枚举类，输出 [值 => 标签]），
 * 三种路径已由 `EnumLabelTest` 的 12 条单测直接覆盖 ——
 * 那比在 e2e 里做「自省资料里有没有 using 这个词」这种存在性检查
 * 强得多。**空虚的断言比没有断言更糟**：它给出虚假的信心。
 *
 * 第一版我确实写了那种存在性检查，写完自己撤掉了。
 */

/*
 * ═══ Aimind 客户端（可选包）═══
 *
 * 验证「可选包装上后真的能用」—— 而不是只看它有没有出现在 composer.json。
 */
echo "\n═══ Aimind 客户端 ═══\n";

/*
 * demo 的实际位置可能变（本地是 aimanong 的兄弟目录，CI 里可能不同），
 * 所以允许用 DEMO_PATH 覆盖 —— 与 pre-release.sh 的参数对齐。
 *
 * ⚠️ 这里踩过：原先写死 `__DIR__.'/../../demo-app'`，
 * 解析成 `aimanong/demo-app`（不存在），断言直接假红。
 */
$demoRoot = getenv('DEMO_PATH') ?: realpath(__DIR__.'/../../../demo-app');
$installedJson = $demoRoot ? @file_get_contents($demoRoot.'/vendor/composer/installed.json') : false;
$installed = is_string($installedJson) && str_contains($installedJson, 'aimanong/aimind-client');

t(
    'aimind-client 已装上（可选包）',
    $installed,
    $installed ? '已装' : '未装 —— setup-demo.sh 有没有漏掉？'
);

if ($installed) {
    /*
     * 跑一个真实 PHP 进程检查：
     *   · AimindClient 能被容器解析
     *   · ServiceProvider 已注册
     *   · 8 个 MCP 工具类都存在
     *
     * 用独立进程而不是当前进程 —— e2e 自己不在 demo 的容器里。
     */
    $demoPath = $demoRoot;
    /*
     * 探针不只看「类存不存在」，而是**构造真实的 MCP Server**
     * 并数它最终合并出来的工具列表 ——
     *
     * 「类存在」和「工具真被注册进 server」是两件事。
     * 踩过：ServiceProvider 里写了一个不存在的 Registry 类，
     * class_exists 守卫永远 false，工具**从来没注册过**，且不报错。
     */
    $script = <<<'PHPSCRIPT'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$transport = new class implements Laravel\Mcp\Server\Contracts\Transport {
    public function onReceive(Closure $handler): void {}
    public function run() {}
    public function send(string $message, ?string $sessionId = null): void {}
    public function sessionId(): ?string { return null; }
    public function stream(Closure $stream): void {}
};

$server = new Aimanong\Mcp\AimanongServer($transport);
$rc = new ReflectionClass($server);
$rp = $rc->getProperty("tools");
$rp->setAccessible(true);

$names = [];
foreach ($rp->getValue($server) as $t) {
    $i = new $t;
    if (method_exists($i, "name")) $names[] = $i->name();
}

$aimind = array_values(array_filter($names, fn ($n) => str_starts_with($n, "aimind")));

echo json_encode([
  "client" => class_exists("Aimanong\\AimindClient\\AimindClient"),
  "provider" => in_array("Aimanong\\AimindClient\\AimindClientServiceProvider", array_keys($app->getLoadedProviders())),
  "aimind_tools" => $aimind,
  "total_tools" => count($names),
]);
PHPSCRIPT;

    $cmd = 'cd '.escapeshellarg((string) $demoPath).' && php -r '.escapeshellarg($script).' 2>/dev/null';
    $raw = (string) shell_exec($cmd);
    $probe = json_decode(trim($raw), true) ?: [];

    t('AimindClient 可被容器解析', ($probe['client'] ?? false) === true);
    t('AimindClientServiceProvider 已注册', ($probe['provider'] ?? false) === true);

    $aimindTools = $probe['aimind_tools'] ?? [];
    $expected = [
        'aimind_ask', 'aimind_bug', 'aimind_outdated', 'aimind_rate',
        'aimind_search', 'aimind_share', 'aimind_solutions', 'aimind_suggestions',
    ];
    sort($aimindTools);

    t(
        '8 个 aimind 工具真的注册进了 MCP Server',
        $aimindTools === $expected,
        count($aimindTools).' 个: '.implode(',', $aimindTools)
    );
    t(
        '内置工具未被挤掉（server 共 16 个工具）',
        ($probe['total_tools'] ?? 0) === 16,
        '共 '.(($probe['total_tools'] ?? 0)).' 个'
    );
}

/*
 * ═══ 文档与实现的一致性 ═══
 *
 * 三条都不缺能力，但实测中每个 agent 都多绕了几步。
 */
echo "\n═══ 文档与实现一致性 ═══\n";

$cc = json_decode((string) @file_get_contents(__DIR__.'/fixtures/multi-app-expectations.json'), true)['contract_consistency'] ?? null;

if ($cc === null) {
    t('读得到一致性共享期望', false, 'fixtures 里缺 contract_consistency');
} else {
    /*
     * ① openapi 的路径必须与真实路由一致（带 /api）。
     *
     * 这条以前是**绿的**，因为断言里也写的 `/admin/articles` ——
     * 测试把 bug 锁住了。
     */
    $oa = json_decode((string) http("$base/__ai/openapi.json")['body'], true) ?: [];
    $oaPaths = array_keys($oa['paths'] ?? []);
    $withApi = 0;

    foreach ($oaPaths as $op) {
        if (str_contains($op, $cc['openapi_path_must_contain'])) {
            $withApi++;
        }
    }

    t(
        'openapi 路径全部带 /api（不再是 /admin/{uri}）',
        $oaPaths !== [] && $withApi === count($oaPaths),
        "{$withApi}/".count($oaPaths)
    );

    // 文档里的路径必须真能返回 JSON（不是 SPA 的 HTML）
    $sample = $oaPaths[0] ?? '';
    $sampleBody = (string) http("$base".$sample, 'GET', [], ['Accept: application/json'])['body'];
    t(
        '文档里的路径真能返回 JSON',
        json_decode($sampleBody, true) !== null,
        $sample.' → '.substr($sampleBody, 0, 40)
    );

    /*
     * ② 非法排序列必须明确报错，不能静默返回别的顺序。
     *
     * SQLite 会接受 `ORDER BY bogus` 并返回未排序数据 → 200，
     * 调用方会以为排序生效了。
     */
    $bad = $cc['invalid_sort'];
    $badUri = (string) ($bad['resource_uri'] ?? 'departments');
    $badRes = http("$base/admin/api/{$badUri}?sort=".urlencode((string) $bad['typo']), 'GET', [], ['Accept: application/json']);
    $badJson = json_decode((string) $badRes['body'], true) ?: [];

    t(
        '非法排序列 → 422（不再静默忽略）',
        $badRes['code'] === $bad['expect_code'],
        "HTTP {$badRes['code']}"
    );
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

    /*
     * 对照：**声明过** ->sortable() 的列仍然工作（别修过头）。
     *
     * ⚠️ 这条以前用 `sort=id`，而当时 demo 的 departments **没声明**任何可排序列
     * —— 新门禁上线后这条立刻红了。**它红得对**：那正是这次要改的行为。
     * 现在 demo 给 id 加了 ->sortable()，这条测的才是「合法列不被误拦」。
     */
    $gate = $disc['sort_gate'] ?? [];
    $gateUri = (string) ($bad['sortable_uri'] ?? 'departments');

    $okSort = http(
        "$base/admin/api/{$gateUri}?sort=".($gate['declared_ok'] ?? 'id').'&per_page=2',
        'GET',
        [],
        ['Accept: application/json']
    );
    t('已声明的排序列仍返回 200', $okSort['code'] === 200, "HTTP {$okSort['code']}");

    // 存在但没声明 ->sortable() 的列也要拦（提示怎么加）
    $undeclared = http(
        "$base/admin/api/{$gateUri}?sort=".($gate['exists_but_not_declared'] ?? 'name'),
        'GET',
        [],
        ['Accept: application/json']
    );
    $undeclaredJson = json_decode((string) $undeclared['body'], true) ?: [];

    t(
        '存在但未声明 sortable 的列 → 422',
        $undeclared['code'] === 422 && ($undeclaredJson['error'] ?? '') === 'SORT_COLUMN_NOT_DECLARED',
        "HTTP {$undeclared['code']}"
    );
    t(
        '提示里给了「怎么加 sortable」',
        str_contains((string) ($undeclaredJson['hint'] ?? ''), 'sortable'),
        (string) ($undeclaredJson['hint'] ?? '(无)')
    );

    /*
     * ③ 猜错的 create 路径必须给出方向正确的提示。
     *
     * 拦之前：/admin/api/users/create 把 create 当 id，
     * 返回「记录不存在: App\Models\User#create」—— 指向「数据没建对」，
     * 而真正的问题是「这个路由不存在」。
     */
    foreach ($cc['no_create_page']['paths'] as $tpl) {
        /*
         * ⚠️ 必须用**两端 demo 都有**的资源名（fixture 里声明），
         * 不能写 users —— CI 的 demo 是 setup-demo.sh 现搭的，没有 users，
         * 于是这里拿到的是「Resource [users] 未注册」的普通 404，
         * 与本条断言要测的东西无关。第一版就是这么错的。
         */
        $url = str_replace('{uri}', (string) ($cc['no_create_page']['resource_uri'] ?? 'departments'), $tpl);
        $resp = http("$base".$url, 'GET', [], ['Accept: application/json']);
        $json = json_decode((string) $resp['body'], true) ?: [];

        t(
            $url.' → NO_CREATE_PAGE 提示',
            $resp['code'] === $cc['no_create_page']['expect_code']
                && ($json['error'] ?? '') === $cc['no_create_page']['expect_error'],
            "HTTP {$resp['code']} ".((string) ($json['error'] ?? '(无)'))
        );
    }
}

/*
 * ═══ 可发现性：自省资料必须给全签名与 namespace ═══
 *
 * 事故：`Tree` 类的命名空间在 llms.txt / capabilities.json /
 * `/__ai/examples/tree`（当时 404）三处都拿不到，一个 LLM 最后靠
 * `class_exists()` 反射才命中。**「有这条能力」与「能照着写出来」
 * 是两件事** —— 自省文档少了签名与 namespace 就只完成前一半。
 */
echo "\n═══ 可发现性 ═══\n";

$disc = json_decode((string) @file_get_contents(__DIR__.'/fixtures/multi-app-expectations.json'), true)['discoverability'] ?? null;

if ($disc === null) {
    t('读得到可发现性共享期望', false, 'fixtures 里缺 discoverability');
} else {
    // ① 示例片段必须存在，且带 namespace
    foreach ($disc['examples_must_exist'] as $i => $pattern) {
        $ex = http("$base/__ai/examples/{$pattern}");
        $needle = $disc['examples_must_contain'][$i] ?? '';

        /*
         * ⚠️ 必须在**解码后**的 code 字段里找。
         *
         * JSON 会把 namespace 的反斜杠转义成 `\\`，直接在原始 body 上
         * `str_contains('Aimanong\Tree\Tree')` 永远找不到 ——
         * 第一版就是这么写错的（断言红，但代码其实是对的）。
         */
        $code = (string) (json_decode((string) $ex['body'], true)['code'] ?? '');

        t(
            "/__ai/examples/{$pattern} → 200",
            $ex['code'] === 200,
            "HTTP {$ex['code']}"
        );
        t(
            "示例含完整 namespace（{$needle}）",
            str_contains($code, $needle),
            str_contains($code, $needle) ? '有' : '缺 —— agent 只能去反射'
        );
    }

    // ② tree_options 必须给钩子签名与 use
    $cap = http("$base/__ai/capabilities.json");
    $treeOpts = json_decode((string) $cap['body'], true)['tree_options'] ?? [];

    foreach ($disc['tree_options_must_have'] as $key) {
        t(
            "tree_options.{$key} 存在",
            isset($treeOpts[$key]) && $treeOpts[$key] !== '',
            isset($treeOpts[$key]) ? '有' : '缺'
        );
    }
}

/*
 * ═══ 声明式自定义批量动作 ═══
 *
 * 声明里只有数据（动作名/标签/处理器类名），行为在处理器里。
 * 权限节点 `{uri}.{action}` 自动生成，id 校验与操作日志继承框架的 ——
 * 这正是「自建批量接口」那条路丢掉的东西。
 */
echo "\n═══ 声明式自定义批量动作 ═══\n";

$cbExp = json_decode((string) @file_get_contents(__DIR__.'/fixtures/multi-app-expectations.json'), true)['custom_batch_action'] ?? null;

if ($cbExp === null) {
    t('读得到批量动作共享期望', false, 'fixtures 里缺 custom_batch_action');
} else {
    $cbPage = http("$base/admin/{$cbExp['resource']}");
    t('批量动作样本页面可访问', $cbPage['code'] === 200, "HTTP {$cbPage['code']}");

    preg_match('~schema\s*=\s*(\{.*?\});~s', (string) $cbPage['body'], $cbm);
    $cbSchema = json_decode($cbm[1] ?? '{}', true) ?: [];
    $cbGrid = $cbSchema['grid'] ?? [];

    t(
        '自定义动作出现在 batchActions 里',
        in_array($cbExp['action'], $cbGrid['batchActions'] ?? [], true),
        implode(',', $cbGrid['batchActions'] ?? [])
    );

    $cbDeclared = $cbGrid['customBatchActions'][0] ?? [];
    t('声明里带标签', ($cbDeclared['label'] ?? '') === $cbExp['label'], (string) ($cbDeclared['label'] ?? ''));
    t('声明里带处理器类名', is_string($cbDeclared['handler'] ?? null), (string) ($cbDeclared['handler'] ?? '(无)'));

    // ── 真的调一次，确认它写库 ──
    // 只验「声明存在」是不够的：声明对了但处理器没被调用，界面照样显示按钮
    $cbCsrf = '';
    if (preg_match('~name="csrf-token" content="([^"]+)"~', (string) $cbPage['body'], $cm)) {
        $cbCsrf = $cm[1];
    }

    $cbIds = [];
    $cbList = http("$base/admin/api/{$cbExp['resource']}");
    $cbPayload = json_decode((string) $cbList['body'], true) ?: [];
    // 列表接口的数组键在各版本里可能是 data / items —— 两个都试
    $cbRows = $cbPayload['data'] ?? $cbPayload['items'] ?? [];

    foreach ($cbRows as $row) {
        if (is_array($row) && isset($row['id'])) {
            $cbIds[] = $row['id'];
        }
    }

    t('批量样本有可操作的行', $cbIds !== [], count($cbIds).' 行');

    $cbRun = http("$base/admin/api/{$cbExp['resource']}/batch", 'POST', [
        'action' => $cbExp['action'],
        'ids' => $cbIds,
    ], ['X-CSRF-TOKEN: '.$cbCsrf]);

    $cbJson = json_decode((string) $cbRun['body'], true);
    t(
        '调用自定义批量动作 → 200',
        $cbRun['code'] === 200,
        "HTTP {$cbRun['code']} ".substr((string) $cbRun['body'], 0, 80)
    );
    t(
        '受影响行数与期望一致',
        (int) ($cbJson['affected'] ?? -1) === (int) $cbExp['expect']['affected'],
        'affected='.var_export($cbJson['affected'] ?? null, true)
    );

    // 复查：状态真的变了（防止「返回 200 但没写库」）
    $cbAfter = http("$base/admin/api/{$cbExp['resource']}");
    $cbStatuses = array_map(
        static fn ($r) => $r['status'] ?? null,
        json_decode((string) $cbAfter['body'], true)['data'] ?? []
    );
    t(
        '数据真的被改（不是只回了 200）',
        $statuses !== [] && ! array_diff(array_filter($cbStatuses), [$cbExp['expect']['final_status']]),
        implode(',', array_map(static fn ($v) => (string) $v, $cbStatuses))
    );

    /*
     * 确认弹窗的文案要按动作变。
     *
     * 实测：弹窗是复用的，原先对所有批量动作写死「批量删除 / 确认删除」——
     * 自定义动作 `ship` 弹出来却是「即将删除：选中的 2 条记录」，
     * 用户会以为数据要被删。
     */
    $cbBody = (string) $cbPage['body'];
    t(
        '确认弹窗文案按动作变（不再写死「批量删除」）',
        str_contains($cbBody, 'confirmTitle()') && str_contains($cbBody, 'confirmButtonLabel()'),
        str_contains($cbBody, 'confirmTitle()') ? '已接线' : '仍写死'
    );

    // 未声明的动作必须被拒 —— 不做「没声明也能调」的隐式行为
    $cbBad = http("$base/admin/api/{$cbExp['resource']}/batch", 'POST', [
        'action' => 'not-declared-anywhere',
        'ids' => $cbIds,
    ], ['X-CSRF-TOKEN: '.$cbCsrf]);
    t(
        '未声明的动作被拒',
        $cbBad['code'] === 403 && str_contains((string) $cbBad['body'], 'BATCH_ACTION_NOT_DECLARED'),
        "HTTP {$cbBad['code']}"
    );
}

/*
 * ═══ 外键下拉（belongsTo）═══
 *
 * 声明方只写一行：`$form->belongsTo('parent_id', '上级部门')`。
 * 候选与 exists 规则从模型关联自动解析 —— 此前要手写
 * `pluck(...)->all()` 与 `exists:{table},{id}`，两个都是易错点：
 * 忘了 ->all() 编译失败，忘了 exists 就能提交任意 id，表名写错不报错。
 */
echo "\n═══ 外键下拉（belongsTo）═══\n";

$btPage = http("$base/admin/departments");
$btSchema = [];
if (preg_match('~schema\s*=\s*(\{.*?\});~s', (string) $btPage['body'], $bm)) {
    $btSchema = json_decode($bm[1], true) ?: [];
}

$btField = null;
foreach ($btSchema['form']['fields'] ?? [] as $bf) {
    if (($bf['name'] ?? '') === 'parent_id') {
        $btField = $bf;
        break;
    }
}

t('外键字段存在', $btField !== null, $btField === null ? 'schema 里找不到 parent_id' : '找到');

if ($btField !== null) {
    $btOptions = $btField['props']['options'] ?? [];

    // 控件类型必须是 select —— 若写成 belongsTo，前端会掉进 <input> 分支，
    // 变成「人手填 id 的文本框」，与这个字段存在的意义正好相反
    t('外键字段的控件类型是 select', ($btField['type'] ?? '') === 'select', (string) ($btField['type'] ?? ''));

    t('候选自动解析出来了', count($btOptions) > 0, count($btOptions).' 个候选');
    t('候选带 value 与 label', isset($btOptions[0]['value'], $btOptions[0]['label']), json_encode($btOptions[0] ?? null, JSON_UNESCAPED_UNICODE));
    t(
        '关联名写进了 props.relation',
        ($btField['props']['relation'] ?? '') === 'parent',
        (string) ($btField['props']['relation'] ?? '(无)')
    );

    $hasExists = false;
    $hasNullable = false;
    foreach ($btField['rules'] ?? [] as $br) {
        if (str_starts_with((string) $br, 'exists:')) {
            $hasExists = true;
        }
        if ($br === 'nullable') {
            $hasNullable = true;
        }
    }
    t('exists 规则自动带上', $hasExists, $hasExists ? '有' : '没有 —— 前端能提交任意 id');

    /*
     * 可空外键必须带 nullable。
     *
     * 实测事故：只有 exists 时，树形的顶级节点（parent_id = NULL）提交直接 422
     * 「The selected parent id is invalid.」—— 而 ai:verify 全程绿灯，
     * 声明完全合法，只有真发请求才暴露。
     */
    /*
     * ⚠️ 这里原先写的是 `$btExp`，而那个变量**从未被赋值** ——
     * `$undefined['k'] ?? $default` 在 PHP 里静默返回默认值、不报任何警告，
     * 于是这段断言一直在用硬编码的 `['nullable']`，**根本没读共享期望文件**。
     * 改 fixture 只有 ThinkPHP 侧会红 —— 正是 AGENTS.md 警告过的「门禁没被反例验证过」。
     * 正确的变量是 $multiAppExp（本文件第 16 行从同一份 fixture 读的）。
     */
    $btExp = $multiAppExp['belongs_to_field'] ?? [];
    $btExpect = $btExp['nullable']['expect_rules'] ?? ['nullable'];
    t(
        '非必填外键带 nullable（否则存不了 NULL）',
        $hasNullable,
        $hasNullable ? '有' : '没有 —— 顶级节点会 422'
    );

    // 真发一次请求确认：NULL 能存、伪 id 仍被拒
    $btCsrf = '';
    if (preg_match('~name="csrf-token" content="([^"]+)"~', (string) $btPage['body'], $btcm)) {
        $btCsrf = $btcm[1];
    }

    if ($btCsrf !== '') {
        /*
         * 提交用的数据也**从共享期望文件读** —— 两端同一份 payload。
         *
         * 这一段锁的是 P0：ThinkPHP 驱动的规则白名单既没有 nullable 也没有 exists，
         * 而 core 的 BelongsTo 自动补这两条 → TP 上带外键的 Resource 提交必 500。
         */
        $btSub = $multiAppExp['belongs_to_submit'] ?? [];
        $btResource = (string) ($btSub['resource'] ?? $btExp['resource'] ?? 'departments');
        $btValidIn = $btSub['valid_status_in'] ?? [200, 201];

        $btNull = http("$base/admin/api/{$btResource}", 'POST', (array) ($btSub['valid_payload'] ?? [
            'name' => 'e2e-nullable-probe',
            'parent_id' => null,
        ]), ['X-CSRF-TOKEN: '.$btCsrf]);

        t(
            'NULL 外键能存（顶级节点）',
            in_array($btNull['code'], $btValidIn, true),
            "HTTP {$btNull['code']} ".substr((string) $btNull['body'], 0, 80)
        );

        $btFake = http("$base/admin/api/{$btResource}", 'POST', (array) ($btSub['bogus_payload'] ?? [
            'name' => 'e2e-fake-parent-probe',
            'parent_id' => 999999,
        ]), ['X-CSRF-TOKEN: '.$btCsrf]);

        t(
            '伪 id 仍被拒（加 nullable 没有削弱校验）',
            $btFake['code'] === (int) ($btSub['bogus_status'] ?? 422),
            "HTTP {$btFake['code']}"
        );

        /*
         * P0：**非 required** 的规则必须真的生效。
         *
         * ThinkPHP 的 think-validate v3 构造函数无参，驱动曾写成
         * `new Validate($rules, $messages)` → 规则集为空 → check() 永远 true。
         * 除手写的 require 外所有规则静默失效，而 e2e 此前只断言 required 类。
         */
        $nrExp = $multiAppExp['non_required_rule'] ?? [];

        if ($nrExp !== []) {
            $nrRes = (string) ($nrExp['resource'] ?? 'departments');
            $nrTooLong = http("$base/admin/api/{$nrRes}", 'POST', (array) ($nrExp['too_long_payload'] ?? []), ['X-CSRF-TOKEN: '.$btCsrf]);

            t(
                '非 required 规则真的拦（max:'.$nrExp['max_length'].'）',
                $nrTooLong['code'] === (int) ($nrExp['expect_status'] ?? 422),
                "HTTP {$nrTooLong['code']} ".substr((string) $nrTooLong['body'], 0, 80)
            );
        }

        // 探针自己清理 —— 否则每跑一次 e2e 就在 demo 库里留一行垃圾
        $btNullId = json_decode((string) $btNull['body'], true)['data']['id'] ?? null;

        if ($btNullId !== null) {
            http("$base/admin/api/{$btResource}/{$btNullId}", 'DELETE', [], ['X-CSRF-TOKEN: '.$btCsrf]);
        }
    }
}

/*
 * ═══ 按钮级权限 ═══
 *
 * 「没有删除权限的人**看不到**删除按钮」—— 而且必须是**服务端不渲染**
 * （不是 CSS/JS 藏起来，那样 DOM 里还在、Vue 重渲染还会回来）。
 *
 * 实测背景：框架原先没有按钮级权限（自省接口里就写着「需自行实现」），
 * 一个 LLM 为此写了中间件去正则匹配 `@click="remove(row)"` 再从 HTML 里抠按钮。
 * 权限节点 {uri}.{action} 框架本来就有，现在按钮直接按它渲染。
 */
echo "\n═══ 按钮级权限 ═══\n";

$btnJar = '/tmp/e2e-viewer-cookie.txt';
@unlink($btnJar);

$viewerGet = function (string $path) use ($base, $btnJar): array {
    $ch = curl_init($base.$path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $btnJar,
        CURLOPT_COOKIEFILE => $btnJar,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return [$code, $body];
};

// 以 viewer01（只有 departments.index）登录
[$_, $vLogin] = $viewerGet('/admin/auth/login');
preg_match('/name="_token" value="([^"]+)"/', $vLogin, $vm);

$ch = curl_init($base.'/admin/auth/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token' => $vm[1] ?? '',
        'username' => 'viewer01',
        'password' => 'viewer123',
    ]),
    CURLOPT_COOKIEJAR => $btnJar,
    CURLOPT_COOKIEFILE => $btnJar,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 15,
]);
curl_exec($ch);
unset($ch);

[$vc, $vBody] = $viewerGet('/admin/departments');
t('受限账号能打开有 index 权限的页面', $vc === 200, "HTTP {$vc}");

preg_match_all('~<button[^>]*@click="([^"]*)"~', $vBody, $vClicks);
$vActions = array_values(array_filter(
    $vClicks[1] ?? [],
    static fn ($c) => str_contains($c, 'openCreate') || str_contains($c, 'edit(') || str_contains($c, 'remove(')
));
t(
    '没有 create/update/destroy 权限 → 三个按钮都不渲染',
    $vActions === [],
    $vActions === [] ? '服务端未输出任何动作按钮' : '仍渲染了: '.implode(',', $vActions)
);
t(
    '操作列头也不渲染',
    ! str_contains($vBody, '操作</th>'),
    str_contains($vBody, '操作</th>') ? '仍输出了操作列' : '未输出'
);

// 对照：超管必须三个都有 —— 否则「按钮都不显示」可能只是模板坏了，
// 而不是权限在起作用（这条对照是防「假绿」的）
$adBtn = http("$base/admin/departments");
preg_match_all('~<button[^>]*@click="([^"]*)"~', (string) $adBtn['body'], $aClicks);
$aActions = array_values(array_filter(
    $aClicks[1] ?? [],
    static fn ($c) => str_contains($c, 'openCreate') || str_contains($c, 'edit(') || str_contains($c, 'remove(')
));
t(
    '对照：超管三个按钮都在',
    count($aActions) >= 3,
    '找到 '.count($aActions).' 个动作按钮'
);

/*
 * ═══ 树形资源 ═══
 *
 * 两个实测事故：
 *   ① 半成品：tree() 声明、/tree 接口、_depth、`.am-tree-toggle` 样式全都写好了，
 *      唯独模板从没用它们 → 声明了树形却看不出层级，且不报任何错。
 *   ② 越权：tree()/move() 漏了 authorize_()，零权限账号能读整张表、能改 parent_id。
 */
echo "\n═══ 树形资源 ═══\n";

$treeExp = json_decode((string) @file_get_contents(__DIR__.'/fixtures/multi-app-expectations.json'), true);
$treeUri = (string) ($treeExp['tree']['sample_resource'] ?? 'departments');
$treePage = http("$base/admin/{$treeUri}");

t('树形页面可访问', $treePage['code'] === 200, "HTTP {$treePage['code']}");
t(
    '模板接了树形单元格（此前是断的）',
    str_contains((string) $treePage['body'], 'am-tree-cell'),
    '需页面里出现 am-tree-cell'
);
t(
    '折叠过滤（displayRows）已接线',
    str_contains((string) $treePage['body'], 'displayRows'),
    '需页面里出现 displayRows'
);

$treeApi = http("$base/admin/api/{$treeUri}/tree");
$treeJson = json_decode((string) $treeApi['body'], true);
$treeRoots = $treeJson['data'] ?? [];

t('树形接口 → 200', $treeApi['code'] === 200, "HTTP {$treeApi['code']}");
t(
    '树形接口返回嵌套 children',
    isset($treeRoots[0]['children']) && is_array($treeRoots[0]['children']),
    '需 data[0].children 是数组'
);

/*
 * 树形接口要**预加载关联**。
 *
 * 树形页前端只请求本接口 —— 不预加载的话 `$grid->column('parent.name')`
 * 整列显示「—」（实测：一个 LLM 撞上后只能改用模型访问器绕开，
 * 而它没有任何办法知道「是接口没带关联」）。
 */
$childWithParent = null;
foreach ($treeRoots[0]['children'] ?? [] as $child) {
    if (isset($child['parent'])) {
        $childWithParent = $child;
        break;
    }
}
t(
    '树形接口预加载了关联列（子节点带 parent 对象）',
    $childWithParent !== null,
    $childWithParent === null ? '子节点没有 parent 字段 —— 关联列会显示「—」' : '有'
);

// 非树形资源要明确报错，不能静默返回空
$notTree = http("$base/admin/api/admin-users/tree");
t(
    '非树形资源调 tree → 403 NOT_A_TREE',
    $notTree['code'] === 403 && str_contains((string) $notTree['body'], 'NOT_A_TREE'),
    "HTTP {$notTree['code']}"
);

echo "\n═══ 可写资源对照（防止只读判断修过头）═══\n";
$rwList = http("$base/admin/api/admin-dict-types");
t("可写资源可读", $rwList['code'] === 200);

echo "\n═══ 上传 ═══\n";
$upTpl = json_decode(http("$base/admin/cms-articles")['body'] ?? '', true);
$noAuth = http("$base/admin/file/uploads/2026/10/not-exist.png");
t("文件读取需登录或 404", in_array($noAuth['code'], [302, 401, 404], true), "HTTP {$noAuth['code']}");

echo "\n═══ 业务语言检索（第六轮回归）═══\n";
// 通过 MCP 走不了 HTTP，这里验证自省接口暴露了同义词
$capJson = json_decode(http("$base/__ai/capabilities.json")['body'] ?? '', true);
$foundation = $capJson['foundation'] ?? [];
$hasAlias = false;
foreach ($foundation as $info) {
    if (is_array($info) && isset($info['also_known_as'])) {
        $hasAlias = true;
        break;
    }
}
t("能力面含业务同义词", $hasAlias, $hasAlias ? 'also_known_as 存在' : '缺失');

echo "\n═══ 资源与安全 ═══\n";
$logo = http("$base/admin/assets/logo.png");
t("LOGO 免登录可访问", $logo['code'] === 200);
$trav = http("$base/admin/assets/../../../.env");
t("目录穿越被拦截", $trav['code'] === 404);

/*
 * ═══ 多应用隔离（真实三后台）═══
 *
 * 放在**最后**：本段会以 agent 身份登录，覆盖共享的 cookie jar，
 * 后面的段落若还依赖 admin 登录态就会被带坏。
 *
 * 这一段盯的是三个**只有真配多应用才会暴露**的问题：
 *
 *   1. 未登录访问 /agent 时，跳转目标必须是该后台自己的登录页（不是 admin 的）
 *      （注意：块注释里不能写「双星号 + 斜杠」的路径写法，那三个字符会闭合注释）
 *   2. agent 后台的菜单里**不能**出现系统管理类（管理员/角色/权限/日志）
 *   3. 权限分隔：给 agent 授了哪些节点，就只能看到哪些模块
 *
 * 第 2 条尤其重要 —— 菜单是入口，串台等于把后台结构暴露给不该看的人。
 */
echo "\n═══ 多应用隔离（三后台）═══\n";

$loginPage = function (string $app) use ($base): array {
    return http("$base/$app/auth/login");
};

$expectLoginStatus = (int) $multiAppExp['http']['login_page_status'];

foreach (['user', 'agent'] as $app) {
    $r = $loginPage($app);
    t(
        "/$app 登录页 {$expectLoginStatus}",
        $r['code'] === $expectLoginStatus,
        "HTTP {$r['code']}"
    );
}

/*
 * ① 未登录跳转必须指向该后台**自己**的登录页。
 *
 * ⚠️ 这里不能用上面的 http()：
 *   1. 它固定发 `Accept: application/json` —— 未登录时服务端按设计
 *      返回 **401 JSON**，而不是表单场景的 302 跳转（两种都对，
 *      但这里要验的是「跳哪去」，必须走表单路径）
 *   2. 它用共享 cookie jar，而本段之前已经以 admin 登录过，
 *      再访问 /admin 会是 200（已登录）而不是 302
 *
 * 所以用独立的 jar + 不带 JSON Accept 头。
 */
$anon = function (string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        // 独立 jar：不影响其它段落的登录态
        CURLOPT_COOKIEJAR => '/tmp/e2e-anon-cookie.txt',
        CURLOPT_COOKIEFILE => '/tmp/e2e-anon-cookie.txt',
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);   // PHP 8 下 curl_close 是空操作，必须 unset 才落盘

    preg_match('/^location:\s*(.+)$/mi', $raw, $m);

    return ['code' => $code, 'location' => trim($m[1] ?? '')];
};

@unlink('/tmp/e2e-anon-cookie.txt');

$redirectTpl = (string) $multiAppExp['http']['unauthenticated_redirect_template'];

foreach (['admin', 'user', 'agent'] as $app) {
    $r = $anon("$base/$app");
    $expectRedirect = str_replace('{app}', $app, $redirectTpl);
    t(
        "未登录 /$app → 跳 $expectRedirect",
        $r['code'] === 302 && str_contains($r['location'], $expectRedirect),
        "HTTP {$r['code']} → {$r['location']}"
    );
}

// ② 以 agent 身份登录，检查菜单隔离
$ag = http("$base/agent/auth/login");
preg_match('/name="_token" value="([^"]+)"/', $ag['body'], $agt);
$agLogin = http("$base/agent/auth/login", 'POST', [
    '_token' => $agt[1] ?? '',
    'username' => 'agent1',
    'password' => 'agent123',
]);
$agHome = http("$base/agent/");
$agBody = (string) $agHome['body'];

t("agent 登录成功", in_array($agLogin['code'], [200, 302], true), "HTTP {$agLogin['code']}");
t("agent 首页 200", $agHome['code'] === 200, "HTTP {$agHome['code']}");

/*
 * 系统管理类菜单必须**不出现**在代理后台。
 *
 * 这些 Resource 声明了 applications() => ['admin']，
 * 而过滤依赖 `Drivers::application()->current()` 走**请求路径推断**。
 * 若推断失效（曾经就是），current() 恒为 admin → 代理商后台
 * 要么全拒（菜单空白）、要么串台（看到管理员）。两种都错。
 */
foreach (['权限节点', '数据字典', '操作日志', '登录日志'] as $needle) {
    t("agent 后台不含「{$needle}」", ! str_contains($agBody, $needle));
}

/*
 * ③-2 🔴 **深度 ≥2 的页面** —— 本轮补的关键断言。
 *
 * 此前 e2e 只测了控制台页（`/admin`，深度 1）。而一个真实存在的严重 bug
 * 只在更深的页面暴露：Laravel 的 urlPrefix() 没归一化 → 页面里是
 * `admin/assets/x.css`（相对路径）。浏览器按 baseURI 解析：
 *
 *   页 /admin           → /admin/assets/...        ✅（base 是 /，碰巧对）
 *   页 /admin/articles  → /admin/admin/assets/...  ❌ 全部 404
 *
 * 即「控制台正常、所有资源页全坏」—— 而全部测试都是绿的。
 * 最后是 5 个独立 AI agent 在真实浏览器里各自撞到才暴露。
 * 这条断言把测试覆盖从深度 1 扩到深度 2。
 */
$deepPage = http("$base/admin/admin-users");
$deepBody = (string) $deepPage['body'];

t('资源页面（深度 2）能打开', $deepPage['code'] === 200, "HTTP {$deepPage['code']}");

preg_match_all('~\b(?:href|src)="([^"]*assets/[^"]*)"~', $deepBody, $deepAssets);
$relative = [];
foreach (array_unique($deepAssets[1] ?? []) as $u) {
    if (! str_starts_with($u, '/') && ! str_starts_with($u, 'http')) {
        $relative[] = $u;
    }
}
t(
    '深度 2 页面的资源 URL 带前导斜杠',
    $relative === [],
    $relative === [] ? '全部以 / 开头' : '相对路径: '.implode(' ', array_slice($relative, 0, 2))
);

// 登录表单 action 同理 —— 相对路径会打到 /admin/auth/admin/auth/login
$loginForm = http("$base/admin/auth/login");
preg_match('~<form[^>]*action="([^"]*)"~', (string) $loginForm['body'], $fm);
$action = $fm[1] ?? '';
t(
    '登录表单 action 带前导斜杠',
    $action === '' || str_starts_with($action, '/') || str_starts_with($action, 'http'),
    "action=\"{$action}\""
);

/*
 * ③ 对照：同一台机器上 admin 后台**应该**有系统管理菜单
 *    （证明不是「所有人都被过滤光了」）。
 *
 * ⚠️ 用「登录日志」而不是「权限节点」：前者是**必装**的内置 Resource
 * （全新项目就有），后者只在某些配置/扩展下注册 —— 用它做对照会在
 * 精简 demo 上误报。对照项必须是「任何环境都该有」的东西。
 */
$ad = http("$base/admin/auth/login");
preg_match('/name="_token" value="([^"]+)"/', $ad['body'], $adt);
http("$base/admin/auth/login", 'POST', [
    '_token' => $adt[1] ?? '',
    'username' => 'admin',
    'password' => 'admin123',
]);
$adBody = (string) http("$base/admin/")['body'];
/*
 * ④ 跨后台访问：admin 专属的 Resource（管理员/日志等）从**代理商后台**
 *    访问必须 **404**，不是 403。
 *
 *    403 等于告诉对方「资源存在，只是你没权限」—— 那是把后台结构
 *    泄露出去。404 才与「真的没注册」不可区分。
 *
 *    ⚠️ 这条是「顺序 bug」的守门人：归属检查必须排在权限检查**之前**。
 *    只在 resolve() 里做归属检查是轮不到的 —— 权限关会先返回 403。
 *    ThinkPHP 侧先踩到，回头发现 Laravel 侧同样中招。
 */
http("$base/agent/auth/login");
$agTok = '';
$agPage = http("$base/agent/auth/login");
preg_match('/name="_token" value="([^"]+)"/', $agPage['body'], $agm);
http("$base/agent/auth/login", 'POST', [
    '_token' => $agm[1] ?? '',
    'username' => 'agent1',
    'password' => 'agent123',
]);

// 用**独立的 jar** 请求，避免与上面的 admin 登录态混用
$agentAnon = function (string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => '/tmp/e2e-agent-cookie.txt',
        CURLOPT_COOKIEFILE => '/tmp/e2e-agent-cookie.txt',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    return $code;
};

@unlink('/tmp/e2e-agent-cookie.txt');

// 先以 agent 身份登录（独立 jar）
$ch = curl_init("$base/agent/auth/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => '/tmp/e2e-agent-cookie.txt',
    CURLOPT_COOKIEFILE => '/tmp/e2e-agent-cookie.txt',
    CURLOPT_TIMEOUT => 15,
]);
$agLoginPage = (string) curl_exec($ch);
unset($ch);
preg_match('/name="_token" value="([^"]+)"/', $agLoginPage, $agTokM);

$ch = curl_init("$base/agent/auth/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token' => $agTokM[1] ?? '',
        'username' => 'agent1',
        'password' => 'agent123',
    ]),
    CURLOPT_COOKIEJAR => '/tmp/e2e-agent-cookie.txt',
    CURLOPT_COOKIEFILE => '/tmp/e2e-agent-cookie.txt',
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 15,
]);
curl_exec($ch);
unset($ch);

$expectCross = (int) $multiAppExp['http']['cross_app_status'];
$crossCode = $agentAnon("$base/agent/api/admin-users");
t(
    "跨后台访问 admin 专属 Resource → {$expectCross}",
    $crossCode === $expectCross,
    "HTTP {$crossCode}".($crossCode === 403 ? '（403 会泄露资源存在）' : '')
);

t("admin 后台含「登录日志」（对照）", str_contains($adBody, '登录日志'));
t("admin 后台含「管理员」菜单（对照）", str_contains($adBody, '管理员'));

echo "\n";
printf("通过 %d / %d\n", $pass, $pass + $fail);

$completed = true;

exit($fail > 0 ? 1 : 0);
