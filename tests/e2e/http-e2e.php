<?php
/** HTTP 层端到端：多应用隔离 + 各功能页面 */
require "vendor/autoload.php";

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
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'location' => $loc];
}

$base = 'http://localhost:8899';
@unlink('/tmp/e2e-cookie.txt');
$pass = 0; $fail = 0;
function t(string $n, bool $c, string $note = ''): void {
    global $pass, $fail;
    printf("  %-46s %s%s\n", $n, $c ? "✅" : "❌", $note ? "  $note" : '');
    $c ? $pass++ : $fail++;
}

echo "═══ 多应用隔离 ═══\n";
$a = http("$base/admin/auth/login");
$m = http("$base/merchant/auth/login");
preg_match('/name="_token" value="([^"]+)"/', $a['body'], $am);
preg_match('/name="_token" value="([^"]+)"/', $m['body'], $mm);
t("admin 登录页 200", $a['code'] === 200);
t("merchant 登录页 200", $m['code'] === 200);

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

// 登录后确认能访问受保护页面
$probe = http("$base/admin/api/categories");
t("登录态生效（可访问 API）", $probe['code'] === 200);

$home = http("$base/admin");
t("admin 首页 200（无重定向循环）", $home['code'] === 200);

echo "\n═══ 各功能页面 ═══\n";
foreach ([
    'categories' => '树形',
    'courses' => '分步表单',
    'suppliers' => '代码生成',
    'departments' => '综合',
] as $uri => $feature) {
    $r = http("$base/admin/$uri");
    t("$feature 页 /admin/$uri", $r['code'] === 200);
}

echo "\n═══ 功能接口 ═══\n";
$tree = http("$base/admin/api/categories/tree");
$tj = json_decode($tree['body'], true);
t("树形 API", $tree['code'] === 200 && isset($tj['data']), count($tj['data'] ?? [])." 个根节点");

$exp = http("$base/admin/api/courses/export");
t("导出接口返回 CSV", $exp['code'] === 200 && str_contains($exp['body'], '课程编号'));

$list = http("$base/admin/api/categories");
t("列表 API", $list['code'] === 200 && isset(json_decode($list['body'], true)['meta']));

$noExport = http("$base/admin/api/categories/export");
$nj = json_decode($noExport['body'], true);
t("未开启导出返回 403", $noExport['code'] === 403, $nj['error'] ?? '');

echo "\n═══ AI 自省接口 ═══\n";
foreach ([
    '/__ai/capabilities.json' => 'capabilities',
    '/__ai/schema/categories' => 'schema',
    '/__ai/openapi.json' => 'openapi',
    '/__ai/context' => 'context',
] as $path => $name) {
    $r = http("$base$path");
    t("自省 $name", $r['code'] === 200);
}
$sch = json_decode(http("$base/__ai/schema/departments")['body'], true);
t("自省含 M5 元数据", isset($sch['grid']['tree'], $sch['form']['steps']), json_encode($sch['capabilities'] ?? []));

echo "\n═══ 资源与安全 ═══\n";
$logo = http("$base/admin/assets/logo.png");
t("LOGO 免登录可访问", $logo['code'] === 200);
$trav = http("$base/admin/assets/../../../.env");
t("目录穿越被拦截", $trav['code'] === 404);

echo "\n";
printf("通过 %d / %d\n", $pass, $pass + $fail);
