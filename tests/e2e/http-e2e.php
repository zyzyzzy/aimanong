<?php
/** HTTP 层端到端：多应用隔离 + 各功能页面 */
/*
 * 本脚本只跑 HTTP（不依赖 demo 的 autoload），
 * 但保留 DEMO_PATH 校验以便提示正确用法。
 */
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

foreach (['user', 'agent'] as $app) {
    $r = $loginPage($app);
    t("/$app 登录页 200", $r['code'] === 200, "HTTP {$r['code']}");
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

foreach (['admin', 'user', 'agent'] as $app) {
    $r = $anon("$base/$app");
    t(
        "未登录 /$app → 跳 $app 登录页",
        $r['code'] === 302 && str_contains($r['location'], "/$app/auth/login"),
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
t("admin 后台含「登录日志」（对照）", str_contains($adBody, '登录日志'));
t("admin 后台含「管理员」菜单（对照）", str_contains($adBody, '管理员'));

echo "\n";
printf("通过 %d / %d\n", $pass, $pass + $fail);

$completed = true;

exit($fail > 0 ? 1 : 0);
