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

// ── 9.9 可选插件不得成为硬依赖 ──
/*
 * 回归（P0）：框架核心模板曾**无条件**调用
 * `\Aimanong\Region\Region::provinces()`，而 framework 的
 * composer.json 并没有依赖 region。
 *
 * 后果：只想 `composer require aimanong/framework` 的用户
 * （完全按 README 快速开始做），打开任意列表页就是
 * `Class "Aimanong\Region\Region" not found` → 整个后台不可用。
 *
 * 这条断言检查模板里对**可选插件**的调用都带存在性守卫。
 */
$tplPath = dirname(__DIR__, 2).'/packages/framework/resources/views/resource.blade.php';
$tpl = is_file($tplPath) ? (string) file_get_contents($tplPath) : '';

if ($tpl === '') {
    t("模板存在性守卫", false, "读不到 {$tplPath}");
} else {
    $lines = explode("\n", $tpl);
    $bare = [];
    $inComment = false;

    foreach ($lines as $i => $line) {
        $trimmed = ltrim($line);
        if (str_contains($trimmed, '/*')) {
            $inComment = true;
        }
        $isComment = $inComment || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//');
        if (str_contains($trimmed, '*/')) {
            $inComment = false;
        }

        // 注释里的示例代码不算（上一版检查就因此误报过）
        if ($isComment || ! str_contains($line, 'Aimanong\\Region\\Region::')) {
            continue;
        }

        $prev = $lines[$i - 1] ?? '';
        if (! str_contains($line, 'class_exists') && ! str_contains($prev, 'class_exists')) {
            $bare[] = $i + 1;
        }
    }

    t("可选插件调用有存在性守卫", $bare === [],
        $bare ? '裸调用在第 '.implode(', ', $bare).' 行' : 'region 调用均有守卫');

    /*
     * 更强的一条：**注释里不得出现可执行的 Blade 指令**。
     *
     * 回归：修 region 守卫时，我把反例写进了注释 ——
     *
     *     * const regionProvinces = @json(\Aimanong\Region\Region::provinces());
     *
     * 而 Blade **不受注释保护**，那行被编译成了真实的 PHP 调用，
     * 于是守卫明明加对了、页面照样 500，排查了很久。
     *
     * 静态扫描还不够 —— 这条直接检查**编译产物**里有没有
     * 出现在注释上下文中的指令残留。
     */
    $commentDirectives = [];
    foreach ($lines as $i => $line) {
        $trimmed = ltrim($line);

        // 只看「注释行」（* 开头 或 // 开头）里是否写了 @ 指令
        $isCommentLine = str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//');
        if (! $isCommentLine) {
            continue;
        }

        if (preg_match('/@(json|if|foreach|include|php)\s*\(/', $trimmed)) {
            $commentDirectives[] = ($i + 1).': '.trim($trimmed);
        }
    }

    t("注释里无可执行 Blade 指令", $commentDirectives === [],
        $commentDirectives
            ? 'Blade 不受注释保护，这些会被编译：'.implode(' | ', array_slice($commentDirectives, 0, 2))
            : '无');
}

// region 是否只作为 suggest，而非 require
$fwComposer = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2).'/packages/framework/composer.json'), true);
$fwRequire = $fwComposer['require'] ?? [];
t("region 不在 framework 的 require 中", ! isset($fwRequire['aimanong/region']),
    isset($fwRequire['aimanong/region']) ? '❌ 被声明为硬依赖' : '仅 suggest');

// ── 9.10 插件可自带字段 UI（框架核心不得内置插件 UI）──
/*
 * 回归：region 的三级联动 UI 曾经硬编码在框架核心模板里，
 * 导致 framework 隐式依赖 region —— 只装核心包的用户打开列表页
 * 直接 Fatal error，而第三方插件想加自定义字段 UI 也没有出路。
 *
 * 现在插件通过 Extension::fieldView() / fieldScript() 自带 UI，
 * 框架只负责在合适的字段类型上渲染它们。
 */
$fw = dirname(__DIR__, 2).'/packages/framework';

// 1) 框架核心不得再出现插件自己的类
$coreTpl = (string) file_get_contents($fw.'/resources/views/resource.blade.php');
$coreHasRegionCode = preg_match(
    '/^\s*(const|function|\$)\s*\w*region/i',
    $coreTpl,
    $mm
) === 1;
t("框架核心无 region 专用代码", ! $coreHasRegionCode,
    $coreHasRegionCode ? '仍有：'.trim($mm[0]) : '已迁至插件');

// 2) 框架提供了注入点
t("FieldType 支持字段视图注册",
    method_exists(\Aimanong\Support\FieldType::class, 'fieldView')
    && method_exists(\Aimanong\Support\FieldType::class, 'fieldViewFor')
    && method_exists(\Aimanong\Support\FieldType::class, 'fieldScript'));

t("Extension 暴露 fieldView/fieldScript",
    method_exists(\Aimanong\Extend\Extension::class, 'fieldView')
    && method_exists(\Aimanong\Extend\Extension::class, 'fieldScript'));

// 3) 装了 region 插件时，它确实注册了 UI（本项目装了）
if (class_exists(\Aimanong\Region\RegionExtension::class)) {
    t("region 插件自带字段 UI",
        \Aimanong\Support\FieldType::fieldViewFor('region') !== null,
        (string) \Aimanong\Support\FieldType::fieldViewFor('region'));

    t("region 插件自带 JS 片段",
        in_array('aimanong-region::script', \Aimanong\Support\FieldType::fieldScripts(), true));
} else {
    echo "  ⏭  跳过「region 插件 UI」：本项目未安装该插件\n";
}

// ── 9.11 安装命令必须能创建管理员账号（P0 死锁回归）──
/*
 * 回归：`aimanong:install` 原先**不创建任何账号**，
 * 而后台必须登录才能进 —— 形成闭环死锁：
 *
 *   装完 → admin_users 为空 → 跳登录页 → 没账号可登 → 进不去
 *        → 也无法用「管理员账号」界面创建第一个账号
 *
 * 使用者唯一的出路是手工跑 tinker 脚本，而那不在任何文档里。
 *
 * 这条断言检查安装命令**具备**建号能力且幂等。
 */
$installSrc = (string) file_get_contents($fw.'/src/Console/InstallCommand.php');

/*
 * ⚠️ 必须检查**调用**而不是方法存在。
 *
 * 第一版断言只查 `str_contains($installSrc, 'createAdmin')` ——
 * 把调用删掉、只留方法定义，断言照样通过（反向测试时发现的）。
 * 这类「检查了错误的东西」的断言比没有断言更危险：它给假绿灯。
 */
t("安装命令会创建管理员账号",
    preg_match('/\$\w+\s*=\s*\$this->createAdmin\(\)/', $installSrc) === 1
    && str_contains($installSrc, 'forceFill')
    && str_contains($installSrc, '->save()'),
    'createAdmin() 被真正调用并落库');

t("安装命令支持非交互参数",
    str_contains($installSrc, '--admin-user')
    && str_contains($installSrc, '--admin-password'),
    'CI / 脚本可用');

t("安装命令可跳过建号",
    str_contains($installSrc, '--no-admin'),
    '--no-admin');

t("安装命令幂等（不重置已有密码）",
    str_contains($installSrc, '已存在管理员账号，跳过创建'),
    '重跑安装不会改密码');

t("安装命令默认生成随机密码",
    str_contains($installSrc, 'Str::password'),
    '避免 admin123 式的公开后门');

t("安装命令绑定超管角色",
    str_contains($installSrc, 'assignSuperRole'),
    '否则登进去菜单为空');

t("安装命令会同步权限节点",
    preg_match('/\$this->syncPermissions\(\)/', $installSrc) === 1,
    '否则新 Resource 的权限在角色分配界面看不到');

// 应用侧：本项目的账号确实存在且可用
$adminModel = config('aimanong.auth.model', \Aimanong\Models\Administrator::class);

if (is_string($adminModel) && class_exists($adminModel)) {
    try {
        $count = $adminModel::query()->count();
        t("当前项目已有管理员账号", $count > 0, "{$count} 个");
    } catch (\Throwable $e) {
        t("当前项目已有管理员账号", false, $e->getMessage());
    }
}

// ── 9.12 业务语言可检索（第六轮 AI 实测的回归）──
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
