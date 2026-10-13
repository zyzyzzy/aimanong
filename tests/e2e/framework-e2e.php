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

$pass = 0;
$fail = 0;

/*
 * 没跑到结尾就退出 = 中途崩了 = 失败。
 * 见文件末尾的说明：Laravel 的异常处理器不会改变退出码。
 */
$completed = false;
register_shutdown_function(static function (): void {
    global $completed;

    if (! $completed) {
        fwrite(STDERR, "\n❌ e2e 未跑完（中途崩溃）—— 判定为失败\n");

        exit(1);
    }
});

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
$corePkg = dirname(__DIR__, 2).'/packages/core';

/*
 * 读框架源码 —— 同时搜 core 与 framework 两个包。
 *
 * ## 为什么不能写死路径
 *
 * 拆包后 `Aimanong\` 这个命名空间横跨两个包：Grid / Form / Schema 在 core，
 * Http / Console 在 framework。原先这里写死 `$fw.'/src/Grid/Grid.php'`，
 * 搬包那一刻就变成「文件不存在」—— 而 Laravel 的异常处理器会把
 * ErrorException 渲染出来却**不改变退出码**，于是 pre-release 仍然
 * 报「✅ framework-e2e 通过」。一个崩了的测试被显示成通过。
 *
 * 所以这里两点一起改：路径搜索 + 末尾的退出码保证。
 */
function fwSrc(string $rel): string
{
    global $fw, $corePkg;

    static $cache = [];

    foreach ([$corePkg, $fw] as $pkg) {
        $path = $pkg.'/'.$rel;

        if (is_file($path)) {
            return $cache[$path] ??= (string) file_get_contents($path);
        }
    }

    fwrite(STDERR, "❌ 找不到源码文件：{$rel}（已搜 packages/core 与 packages/framework）\n");
    exit(1);
}

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
$installSrc = fwSrc('src/Console/InstallCommand.php');

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

// ── 9.12 生产部署缓存兼容性（P0 回归）──
/*
 * ## 为什么必须锁死
 *
 * Laravel 官方部署文档的第一步就是：
 *
 *     php artisan config:cache
 *     php artisan route:cache
 *     php artisan view:cache
 *
 * 而框架曾经在跑完这两条后**整个后台 500**：
 *
 *   1. config:cache  → Auth driver [admin] ... is not defined
 *      根因：多应用 guard 的注册被「配置里已有该 guard」的判断跳过，
 *            而 Auth::extend() 注册的是**运行时**状态，缓存不含它
 *
 *   2. route:cache   → Target class [admin.admin] does not exist
 *      根因：bootApplication() 在 routesAreCached() 时整段 return，
 *            连中间件组都没注册 —— 而缓存文件里存的是组名字符串
 *
 * 两个都不报在编译期、不在单测里、不在 e2e 里，
 * 因为它们都**只在生产部署路径上出现**。
 */
$providerSrc = fwSrc('src/AimanongServiceProvider.php');

t("guard 注册不被配置缓存跳过",
    str_contains($providerSrc, 'guardDriverRegistered'),
    '检查运行时 driver 是否真的注册了');

t("中间件组注册不被路由缓存跳过",
    str_contains($providerSrc, 'registerApplicationRuntime'),
    '运行时注册与路由加载分离');

// 行为验证：当前进程里，每个已注册的应用都应有对应的中间件组
$router = app('router');
$groups = (function (): array {
    /** @var array<string, array<int, string>> $g */
    $g = $this->middlewareGroups;

    return $g;
})->call($router);

$apps = \Aimanong\Aimanong::application();

if ($apps->enabled()) {
    $missing = [];
    foreach ($apps->names() as $name) {
        if (! isset($groups['admin.'.$name])) {
            $missing[] = 'admin.'.$name;
        }
    }
    t("每个应用都有中间件组", $missing === [],
        $missing ? '缺: '.implode(',', $missing) : implode(',', $apps->names()));
} else {
    t("单后台中间件组存在", isset($groups['admin']), 'admin');
}

/*
 * guard 的 driver 必须真的注册在运行时。
 *
 * ⚠️ 不能用 `AuthManager::hasCustomCreator()` —— Laravel 12 没这个方法
 * （第一版就这么写的，断言假红）。改用**行为验证**：
 * 真的去解析一次 guard，能拿到实例才算注册成功。
 */
$resolved = [];
$failed = [];

foreach ($apps->enabled() ? $apps->names() : ['admin'] as $name) {
    $guard = $apps->enabled() ? $apps->guard($name) : 'admin';

    try {
        $instance = app('auth')->guard($guard);

        if ($instance instanceof \Aimanong\Auth\AdminGuard) {
            $resolved[] = $guard;
        } else {
            $failed[] = $guard.' → '.get_class($instance);
        }
    } catch (\Throwable $e) {
        $failed[] = $guard.' → '.$e->getMessage();
    }
}

t("guard 可被真实解析", $failed === [] && $resolved !== [],
    $failed ? implode('; ', $failed) : implode(',', $resolved));

// ── 9.13 筛选器：声明 ->filter() 必须有 UI（幽灵能力回归）──
/*
 * 回归：`->filter()` 曾经是**幽灵能力** ——
 * schema 老实产出 `"filterable": true`，后端 `?filters[x]=y` 也真生效，
 * 但页面上一个筛选控件都不渲染。
 *
 * 第六轮 AI 实测的真实反馈：「我最后移除了这个声明 ——
 * 留着只会骗下一个读 schema 的人」。
 */
$tpl = (string) file_get_contents(dirname(__DIR__, 2)
    .'/packages/framework/resources/views/resource.blade.php');

t("筛选器有 UI（filterableColumns）",
    str_contains($tpl, 'filterableColumns')
    && str_contains($tpl, 'filterOptions'),
    '工具栏会渲染筛选控件');

t("筛选值会传给后端",
    preg_match('/filters\s*\[\s*\$\{|filters\[/', $tpl) === 1,
    '?filters[列]=值');

t("有 map/dict 时用下拉框",
    str_contains($tpl, 'filterOptions(col)'),
    '选项已知 → 下拉；否则输入框');

// 后端协议本身（早已可用，这里锁死防退化）
t("后端支持 filters 参数",
    str_contains(
        fwSrc('src/Repository/EloquentRepository.php'),
        'applyFilters'
    ));

// ── 9.14 登录限流（安全基线回归）──
/*
 * 回归：登录接口原本**没有任何速率限制** ——
 * 实测连续 10 次错误密码全部 422，零拦截。
 *
 * 攻击者可对暴露公网的后台无限次尝试密码；
 * 登录日志会记录失败，但日志不能阻止攻击。
 */
$authSrc = fwSrc('src/Http/Controllers/AuthController.php');
$throttleSrc = (is_file($corePkg.'/src/Auth/LoginThrottle.php') || is_file($fw.'/src/Auth/LoginThrottle.php'))
    ? fwSrc('src/Auth/LoginThrottle.php')
    : '';

t("存在登录限流器", $throttleSrc !== '', 'LoginThrottle');

t("登录前先检查是否被锁",
    str_contains($authSrc, 'ensureNotLockedOut'),
    '先检查再 attempt —— 否则限流了但每次仍查库+校验密码');

t("失败会计数", str_contains($authSrc, 'LoginThrottle::hit'));
t("成功会清零", str_contains($authSrc, 'LoginThrottle::clear'));

t("限流双维度（IP + IP×账号）",
    str_contains($throttleSrc, 'per_ip') && str_contains($throttleSrc, 'per_account'),
    '只按 IP 挡不住分布式');

t("限流可配置开关",
    str_contains((string) file_get_contents($fw.'/config/aimanong.php'), 'throttle'),
    'auth.throttle.enable');

// ── 9.15 批量操作：声明 -> 编译 -> schema -> 后端 -> 前端 全链路 ──
/*
 * 回归：`$grid->batchActions([...])` 曾经是一条**完全断掉的链路** ——
 * 它在 4 处对外文档里许诺了功能（llms.txt / docs/api/columns.md /
 * Capabilities / Grid），但：
 *
 *   1. Grid 存了值，**Compiler 逐项挑进 meta 时漏了它**
 *   2. 即使进了 meta，**emitter 也逐项挑，又漏一次**
 *   3. 后端没有任何 batch 端点
 *   4. 前端没有任何引用
 *
 * 也就是说：声明了、文档写了、能力面列了，实际是个彻底的空操作。
 * 比 `->filter()` 更糟 —— 后者至少后端能用。
 */
$gridSrc = fwSrc('src/Grid/Grid.php');
$compilerSrc = fwSrc('src/Schema/Compiler.php');
$emitterSrc = fwSrc('src/Schema/Emitters/JsonSchemaEmitter.php');
$controllerSrc = fwSrc('src/Http/Controllers/ResourceController.php');
$routeSrc = (string) file_get_contents($fw.'/routes/admin.php');
$tplSrc = (string) file_get_contents($fw.'/resources/views/resource.blade.php');

t("Grid 声明并校验批量动作",
    str_contains($gridSrc, 'BATCH_ACTIONS') && str_contains($gridSrc, 'getBatchActions'),
    '未声明的动作名编译期报错');

t("Compiler 把批量动作带进 meta",
    str_contains($compilerSrc, "'batchActions' =>"),
    '第一层（曾漏）');

t("emitter 把批量动作写进 schema",
    str_contains($emitterSrc, "'batchActions' =>"),
    '第二层（曾漏）');

t("后端有 batch 端点",
    str_contains($routeSrc, "{uri}/batch") && str_contains($controllerSrc, 'BATCH_ACTION_NOT_DECLARED'),
    '未声明动作 → 403');

t("批量删除复用 destroy 权限",
    // 不用 $ 的正则 —— PHP 双引号会把它当变量插值（写错过一次）
    preg_match('/batchDelete.*?authorize_\([^)]*destroy/s', $controllerSrc) === 1,
    '能删一条就该能删一批');

t("前端渲染选择列与批量按钮",
    str_contains($tplSrc, 'hasBatch') && str_contains($tplSrc, 'toggleSelectAll')
    && str_contains($tplSrc, 'runBatch'),
    '复选框 + 批量按钮');

t("导出选中项复用导出端点",
    str_contains($controllerSrc, "\$request->query('ids')")
    && str_contains($tplSrc, "params.append('ids[]'"),
    '参数化而非新端点');

t("导出带上当前筛选条件",
    str_contains($tplSrc, 'applyListParams'),
    '否则「导出与界面一致」不成立');

// ── 9.16 宪法审计发现的第三条违规（2026-10-12）──
/*
 * 依据《CONSTITUTION.md》第三条（零隐式）审计时发现两条幽灵能力。
 * 第三条是条款里**优先级最高**的一条：声明不可信，一切都垮。
 */

// ① divider：声明了必须真的可见（原先被 hidden(true) 过滤，界面上一个字都没有）
$dividerSrc = fwSrc('src/Form/Fields/Divider.php');
$tplSrc2 = (string) file_get_contents($fw.'/resources/views/resource.blade.php');

/*
 * ⚠️ 必须先剥掉注释再检查 ✗→✓
 *
 * 第一版直接 `str_contains($dividerSrc, '$this->hidden(true)')` 会**假红** ——
 * 因为我在解释「为什么改」的注释里正好写了这段字面文字 ✓。
 * 注释不是代码 ✓（同理：Blade 注释里的指令也会被引擎解析）。
 */
$dividerCode = (string) preg_replace(
    '#/\*.*?\*/|//[^\n]*|\s*\*[^\n]*#s',
    '',
    $dividerSrc
);

t("divider 不再被隐藏",
    ! str_contains($dividerCode, 'hidden(true)'),
    '否则 visibleFields 会把它过滤掉 ✗（已剥注释比对）');

t("divider 前端有渲染分支",
    str_contains($tplSrc2, "f.type === 'divider'"),
    '否则声明了也看不见');

t("divider 有可见样式",
    str_contains(
        (string) file_get_contents($fw.'/resources/assets/css/design-system.css'),
        '.am-field__divider'
    ),
    '有结构没样式 = 还是看不见');

// ② draggable：不得在产物里谎报可用
$treeSrc = fwSrc('src/Tree/Tree.php');

t("draggable 默认不谎报",
    str_contains($treeSrc, 'protected bool $draggable = false'),
    '默认 true 会让每个树形资源都写 draggable:true ✗');

t("draggable 不允许声称做不到的事",
    str_contains($treeSrc, 'InvalidArgumentException'),
    'UI 没实现就不该允许声明 true');

t("draggable_supported 仍为 false",
    str_contains($treeSrc, "'draggable_supported' => false"),
    '与 draggable 口径一致');

// 行为验证：真实产物里不得出现 draggable:true
$schemaPath = $demo.'/resources/aimanong/schema.json';
$typesPath = $demo.'/resources/aimanong/types.ts';

if (is_file($schemaPath)) {
    $schemaRaw = (string) file_get_contents($schemaPath);
    t("产物 schema.json 不谎报 draggable",
        ! str_contains($schemaRaw, '"draggable":true'),
        'AI 读这份产物，谎报会导致它写出点不动的代码');
}

if (is_file($typesPath)) {
    $typesRaw = (string) file_get_contents($typesPath);
    t("产物 types.ts 不谎报 draggable",
        ! str_contains($typesRaw, 'draggable: true'),
        '同上');
}

// ── 9.17 业务语言可检索（第六轮 AI 实测的回归）──
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

/*
 * ⚠️ 必须显式设置退出码。
 *
 * Laravel 启动后注册了全局异常处理器：脚本中途崩掉时它会把异常
 * 渲染出来，然后**正常返回** —— PHP 退出码仍是 0。
 * 而 `pre-release.sh` 只看退出码，于是「崩了的 e2e」被显示成
 * 「✅ framework-e2e 通过」（已真实发生：搬包后 Grid.php 路径失效）。
 *
 * 两道保险：
 *   ① 正常跑完 → 按失败数返回
 *   ② 没跑完（崩溃/被中断）→ shutdown 钩子把它改成失败
 */
$completed = true;

exit($fail > 0 ? 1 : 0);
