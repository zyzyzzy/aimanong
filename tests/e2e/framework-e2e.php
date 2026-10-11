<?php
/** 完整端到端测试：覆盖 M0-M6 全部功能 */
/*
 * demo 项目路径：
 *   默认用 DEMO_PATH 环境变量，未设置时用相对路径 ../../
 *   这样脚本可以在任意目录执行。
 */
$demo = getenv('DEMO_PATH') ?: __DIR__.'/../../../demo-app';

if (! is_file($demo.'/vendor/autoload.php')) {
    fwrite(STDERR, "找不到 demo 项目: {$demo}\n");
    fwrite(STDERR, "请设置环境变量 DEMO_PATH，例如：\n");
    fwrite(STDERR, "  DEMO_PATH=/path/to/demo-app php ".basename(__FILE__)."\n");
    exit(1);
}

require $demo.'/vendor/autoload.php';
$app = require $demo.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $note = ''): void {
    global $pass, $fail;
    printf("  %-52s %s%s\n", $name, $cond ? "✅" : "❌", $note ? "  $note" : '');
    $cond ? $pass++ : $fail++;
}

/*
 * ═══════════════════════════════════════════════════════════
 * 被测 Resource 的选取
 * ═══════════════════════════════════════════════════════════
 *
 * ## 为什么要探测而不是写死
 *
 * 这个脚本原先直接引用 `App\Aimanong\CategoryResource` ——
 * 某个特定 demo 项目里的类。后果：在**全新安装**的项目上跑
 * （CI 就是这样），脚本会崩在第一个断言上。
 *
 * 也就是说 e2e 只能在「恰好长成那个样子」的项目上跑，
 * CI 自然用不了它 —— 这正是「CI 不跑 e2e」的技术根因。
 *
 * 现在：优先用富 demo Resource，找不到就用框架内置 Resource
 * （任何装了框架的项目都有）；demo 专有的能力检测则跳过并说明。
 */
function pickResource(array $candidates): ?string
{
    foreach ($candidates as $class) {
        if (class_exists($class)) {
            return $class;
        }
    }

    return null;
}

$richClass = pickResource([
    'App\Aimanong\CategoryResource',
    'App\Aimanong\CmsArticleResource',
    'App\Aimanong\TicketResource',
]);

$courseClass = pickResource(['App\Aimanong\CourseResource']);
$treeClass = pickResource(['App\Aimanong\CategoryResource']);

$probeClass = $richClass ?? \Aimanong\Foundation\Resources\AdminUserResource::class;
$usingRich = $richClass !== null;

if (! $usingRich) {
    echo "ℹ️  未找到 demo 专有 Resource —— 改用框架内置 Resource 做通用断言\n";
    echo "   （全新安装的项目就是这种情况，属正常）\n\n";
}

echo "═══ 1. 框架基础（M0）═══\n";
t("ServiceProvider 已注册", class_exists(\Aimanong\AimanongServiceProvider::class));
t("admin guard 可用", \Aimanong\Aimanong::guard() instanceof \Aimanong\Auth\AdminGuard);
t("管理员模型存在", class_exists(\Aimanong\Models\Administrator::class));

echo "\n═══ 2. Schema 编译（M1）═══\n";
$compiler = new \Aimanong\Schema\Compiler();
$node = $compiler->compile($probeClass);
t("编译产出 AST", $node->uri !== '', "uri={$node->uri}");
$json = (new \Aimanong\Schema\Emitters\JsonSchemaEmitter())->emit($node);
$ts = (new \Aimanong\Schema\Emitters\TypeScriptEmitter())->emit($node);
$oa = (new \Aimanong\Schema\Emitters\OpenApiEmitter())->emit($node);
$ai = (new \Aimanong\Schema\Emitters\AiPromptEmitter())->emit($node);
t("四份产物齐备", $json && $ts && $oa && $ai, "JSON/TS/OpenAPI/AI");

echo "\n═══ 3. 核心 DSL（M2）═══\n";
t("Grid 列可与排序搜索", count($node->columns) > 0);
t("Form 字段与规则", count($node->fields) > 0);
$repo = new \Aimanong\Repository\EloquentRepository($node->model);
t("Repository 分页", $repo->paginate([], 5) instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator);

echo "\n═══ 4. AI 能力层（M3）═══\n";
$caps = (new \Aimanong\Ai\Capabilities())->toArray();
t("capabilities 清单", isset($caps['field_types'], $caps['resources']));
t("MCP Server 可实例化", class_exists(\Aimanong\Mcp\AimanongServer::class));
$verifier = new \Aimanong\Ai\Verifier();
$result = $verifier->verify($probeClass);
t("验证器可用", isset($result['valid']), "valid=".var_export($result['valid'], true));
$checker = new \Aimanong\Ai\RequirementChecker();
$r = $checker->check($probeClass, ['searchable' => 'name']);
t("需求核对器", $r['checked'] === true);

echo "\n═══ 5. 字段与展示器（M4）═══\n";
$types = count(\Aimanong\Support\FieldType::all());
// 内置 26 个 + 插件注册的（如 region）—— 断言下限而非精确值
t("字段类型数量（含插件）", $types >= 26, "{$types} 个");
// 每个展示器单独一列（链式调用是覆盖语义，不是叠加）
$grid = new \Aimanong\Grid\Grid();
$grid->column('a')->bool();
$grid->column('b')->badge();
$grid->column('c')->money();
$grid->column('d')->image();
$grid->column('e')->link();
$grid->column('f')->progress();
$fmts = array_map(fn($c) => $c->formatter, $grid->toNodes());
$expected = ['bool','badge','money','image','link','progress'];
t("6 个展示器全部可用", count(array_intersect($expected, $fmts)) === 6, implode(',', $fmts));

echo "\n═══ 6. M5 增强 ═══\n";

/*
 * 这一节依赖 demo 专有的 Resource（树形 / 分步 / 导出）。
 * 全新项目里没有，跳过时**明确说明**而不是静默通过 ——
 * 「跳过」和「通过」必须是两件事。
 */
if ($courseClass !== null) {
    $courseNode = $compiler->compile($courseClass);
    t("导出已声明", ($courseNode->meta['exportable'] ?? false) === true);
    t("分步表单已声明", ($courseNode->meta['stepped'] ?? false) === true, count($courseNode->meta['steps'] ?? [])." 步");
} else {
    echo "  ⏭  跳过「导出 / 分步表单」：该项目没有 CoursesResource\n";
}

if ($treeClass !== null) {
    $treeNode = $compiler->compile($treeClass);
    t("树形已声明", is_array($treeNode->meta['tree'] ?? null));
} else {
    echo "  ⏭  跳过「树形」：该项目没有 CategoryResource\n";
}

// 循环检测不依赖具体 Resource，用内置 Tree 服务自证
$selfTree = [
    ['id' => 1, 'parent_id' => 0, 'name' => '根'],
    ['id' => 2, 'parent_id' => 1, 'name' => '子'],
];
$cycles = (new \Aimanong\Services\TreeBuilder())->detectCycles(
    $selfTree,
    ['parentColumn' => 'parent_id', 'idColumn' => 'id']
);
t("循环检测正常", $cycles === []);
// 多应用
/*
 * 多应用与插件：**配置了就验证，没配就跳过**。
 *
 * 全新项目默认既不配多应用、也不装插件 —— 那是可选能力，
 * 不是「框架坏了」。把「未配置」判为失败会让 CI 永远红着。
 */
$apps = \Aimanong\Aimanong::application();
if ($apps->enabled()) {
    t("多应用已启用", true, implode(',', $apps->names()));
} else {
    echo "  ⏭  跳过「多应用」：该项目未配置 applications（可选能力）\n";
}

$exts = \Aimanong\Aimanong::extensions();
if ($exts->count() > 0) {
    t("扩展已加载", true, implode(',', $exts->bootedNames()));
} else {
    echo "  ⏭  跳过「插件」：该项目未安装扩展（可选能力）\n";
}

echo "\n═══ 7. M6 生态 ═══\n";
t("代码生成器服务", class_exists(\Aimanong\Services\ResourceGenerator::class));
t("文档生成命令", class_exists(\Aimanong\Console\DocsCommand::class));
/*
 * 代码生成器需要一个**真实存在且有字段**的表。
 * 全新项目的 users 表一定有；demo 项目则用 categories。
 */
$genTable = null;
foreach (['categories', 'users', 'admin_users'] as $candidate) {
    if (\Illuminate\Support\Facades\Schema::hasTable($candidate)
        && \Illuminate\Support\Facades\Schema::getColumns($candidate) !== []) {
        $genTable = $candidate;
        break;
    }
}

if ($genTable !== null) {
    $gen = new \Aimanong\Services\ResourceGenerator();
    $g = $gen->generate($genTable, null, '测试');
    t("生成器产出代码", str_contains($g['code'], 'class '), "表 {$genTable}");
} else {
    t("生成器产出代码", false, '找不到可用的表');
}

echo "\n═══ 8. 应用上下文收口 ═══\n";
$ctx = \Aimanong\Aimanong::context();
t("context 可访问", $ctx instanceof \Aimanong\Application\ApplicationContext);
t("prefix 推断", is_string($ctx->prefix()));
t("guard 推断", is_string($ctx->guard()));
t("CLI 下回退正确", $ctx->prefix() === 'admin' || $ctx->prefix() === 'merchant');

/*
 * ═══════════════════════════════════════════════════════════
 * 9. 基座能力（v1.6.0）
 * ═══════════════════════════════════════════════════════════
 *
 * ## 为什么必须补这一节
 *
 * 这一节此前**完全不存在** —— v1.5.0/v1.6.0 新增了 7 项能力，
 * e2e 覆盖为 0，而发布检查一直是绿的（`《初衷对照评估》偏离 2`）。
 *
 * 本节在**进程内**验证声明层与产物层；真实 HTTP 行为由 http-e2e.php 覆盖。
 */
echo "\n═══ 9. 基座能力（v1.6.0）═══\n";

$registry = \Aimanong\Aimanong::registry()->all();

// ── 9.1 内置 Resource 已注册 ──
$builtin = [
    'admin-operation-logs' => '操作日志',
    'admin-login-logs' => '登录日志',
    'admin-dict-types' => '数据字典',
    'admin-dict-items' => '字典条目',
    'admin-users' => '管理员账号',
];
foreach ($builtin as $uri => $label) {
    t("内置 Resource {$label}", isset($registry[$uri]));
}

// ── 9.2 只读 Resource：声明 → 产物 → 权限，三层一致 ──
$roNode = $compiler->compile(\Aimanong\Foundation\Resources\OperationLogResource::class);
t("只读资源编译", ($roNode->meta['readonly'] ?? false) === true);

$roJson = (new \Aimanong\Schema\Emitters\JsonSchemaEmitter())->emit($roNode);
t("schema 带 readonly", ($roJson['readonly'] ?? false) === true);

$roOa = (new \Aimanong\Schema\Emitters\OpenApiEmitter())->emit($roNode);
$roPaths = $roOa['paths']['/admin/'.$roNode->uri] ?? [];
$roItem = $roOa['paths']['/admin/'.$roNode->uri.'/{id}'] ?? [];
t("OpenAPI 不宣告写接口", ! isset($roPaths['post'], $roItem['put'], $roItem['delete']));

$roActions = \Aimanong\Auth\PermissionGate::actionsFor($roNode);
t("只读资源无写权限节点", $roActions === ['index', 'show', 'export'], implode(',', $roActions));

// ── 9.3 上传能力 ──
$uploader = new \Aimanong\Foundation\Upload\Uploader();
t("上传 URL 模板含占位符", str_contains($uploader->urlTemplate(), '{path}'));
t("上传接口地址可解析", str_contains($uploader->endpoint(), '/api/upload'));
$imgExt = $uploader->allowedExtensions('image');
t("图片白名单排除 svg", in_array('png', $imgExt, true) && ! in_array('svg', $imgExt, true), implode(',', $imgExt));
t("危险路径被拒", $uploader->pathFromValue('uploads/../../.env') === null);
t("外链原样透出", $uploader->urlFromValue('https://cdn.example.com/a.png') === 'https://cdn.example.com/a.png');

// ── 9.4 数据字典 ──
$dict = new \Aimanong\Foundation\Dict\Dictionary();
$all = $dict->all();
t("字典可自省", is_array($all), count($all).' 个');

$dictForm = null;
foreach ($registry as $uri => $class) {
    try {
        $n = $compiler->compile($class);
    } catch (\Throwable) {
        continue;
    }
    foreach ($n->fields as $f) {
        if (($f->props['dict'] ?? null) !== null) {
            $dictForm = $n;
            break 2;
        }
    }
}
/*
 * 全新项目默认没有字典 —— 那是可选能力。
 * 有则验证「字段确实带上了字典选项」，无则跳过。
 */
if ($dictForm !== null) {
    t("字典字段已解析出选项", true, $dictForm->uri);
} else {
    echo "  ⏭  跳过「字典字段」：该项目还没有声明字典（可选能力）\n";
}

// ── 9.5 审计日志 ──
$opCols = array_map(fn ($c) => $c->name, $roNode->columns);
t("操作日志含关键列", in_array('action', $opCols, true) && in_array('resource_uri', $opCols, true));
t("动作词表覆盖 login", array_key_exists('login', \Aimanong\Foundation\Audit\OperationLog::actionOptions()));
t("登录状态词表完整", count(\Aimanong\Foundation\Audit\LoginLog::statusOptions()) === 2);
t("密码类字段被掩码", \Aimanong\Foundation\Audit\AuditRecorder::mask(['password' => 'x'])['password'] === '******');

// ── 9.6 管理员账号 ──
$adminNode = $compiler->compile(\Aimanong\Foundation\Resources\AdminUserResource::class);
t("管理员资源可写", ($adminNode->meta['readonly'] ?? false) === false);
t("密码框 omitWhenEmpty", in_array('password', $adminNode->meta['omitWhenEmpty'] ?? [], true));
t("密码框 requiredOnCreate", in_array('password', $adminNode->meta['requiredOnCreate'] ?? [], true));
t("角色多对多已声明", ($adminNode->meta['relationFields'] ?? []) === ['roles' => 'roles']);

// ── 9.7 个人中心 ──
t("个人中心控制器存在", class_exists(\Aimanong\Foundation\Profile\ProfileController::class));
$routes = app('router')->getRoutes();
$hasProfile = false;
foreach ($routes as $r) {
    if (str_ends_with($r->uri(), 'profile')) {
        $hasProfile = true;
        break;
    }
}
t("个人中心路由已注册", $hasProfile);

// ── 9.8 能力面一致性（基座七项都要能被 AI 自省到）──
$foundation = $caps['foundation'] ?? [];
$need = ['readonly', 'operation_log', 'login_log', 'dict', 'upload', 'profile', 'admin_user'];
$missing = array_values(array_diff($need, array_keys($foundation)));
t("能力面覆盖七项基座", $missing === [], $missing ? '缺: '.implode(',', $missing) : implode(',', array_keys($foundation)));

$dictCaps = $caps['dictionaries'] ?? [];
t("能力面暴露字典清单", is_array($dictCaps), count($dictCaps).' 个');

// ── 9.9 业务语言可检索（第六轮 AI 实测的回归）──
$searchDocs = new \Aimanong\Mcp\Tools\SearchDocs();
$bizHits = 0;
foreach (['运营希望自己改文案', '处理留痕', '现场照片要能传多张'] as $q) {
    $text = $searchDocs->handle(new \Laravel\Mcp\Request(['query' => $q]))
        ->content()->toArray()['text'] ?? '';
    if (! str_contains($text, '未找到')) {
        $bizHits++;
    }
}
t("业务语言可检索到能力", $bizHits === 3, "{$bizHits}/3");

echo "\n";
printf("通过 %d / %d\n", $pass, $pass + $fail);
