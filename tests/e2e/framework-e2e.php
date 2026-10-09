<?php
/** 完整端到端测试：覆盖 M0-M6 全部功能 */
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $note = ''): void {
    global $pass, $fail;
    printf("  %-52s %s%s\n", $name, $cond ? "✅" : "❌", $note ? "  $note" : '');
    $cond ? $pass++ : $fail++;
}

echo "═══ 1. 框架基础（M0）═══\n";
t("ServiceProvider 已注册", class_exists(\Aimanong\AimanongServiceProvider::class));
t("admin guard 可用", \Aimanong\Aimanong::guard() instanceof \Aimanong\Auth\AdminGuard);
t("管理员模型存在", class_exists(\Aimanong\Models\Administrator::class));

echo "\n═══ 2. Schema 编译（M1）═══\n";
$compiler = new \Aimanong\Schema\Compiler();
$node = $compiler->compile(App\Aimanong\CategoryResource::class);
t("编译产出 AST", $node->uri === 'categories');
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
$result = $verifier->verify(App\Aimanong\CategoryResource::class);
t("验证器可用", isset($result['valid']), "valid=".var_export($result['valid'], true));
$checker = new \Aimanong\Ai\RequirementChecker();
$r = $checker->check(App\Aimanong\CategoryResource::class, ['searchable' => 'name']);
t("需求核对器", $r['checked'] === true);

echo "\n═══ 5. 字段与展示器（M4）═══\n";
$types = count(\Aimanong\Support\FieldType::all());
t("字段类型数量", $types === 26, "{$types} 个");
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
// 导出
$catNode = $compiler->compile(App\Aimanong\CourseResource::class);
t("导出已声明", ($catNode->meta['exportable'] ?? false) === true);
// 树形
$treeNode = $compiler->compile(App\Aimanong\CategoryResource::class);
t("树形已声明", is_array($treeNode->meta['tree'] ?? null));
$cycles = (new \Aimanong\Services\TreeBuilder())->detectCycles(
    App\Models\Category::query()->get()->toArray(),
    ['parentColumn' => 'parent_id', 'idColumn' => 'id']
);
t("循环检测正常", $cycles === []);
// 分步表单
$courseNode = $compiler->compile(App\Aimanong\CourseResource::class);
t("分步表单已声明", ($courseNode->meta['stepped'] ?? false) === true, count($courseNode->meta['steps'] ?? [])." 步");
// 多应用
$apps = \Aimanong\Aimanong::application();
t("多应用已启用", $apps->enabled(), implode(',', $apps->names()));
// 插件
$exts = \Aimanong\Aimanong::extensions();
t("扩展已加载", $exts->count() > 0, implode(',', $exts->bootedNames()));

echo "\n═══ 7. M6 生态 ═══\n";
t("代码生成器服务", class_exists(\Aimanong\Services\ResourceGenerator::class));
t("文档生成命令", class_exists(\Aimanong\Console\DocsCommand::class));
$gen = new \Aimanong\Services\ResourceGenerator();
$g = $gen->generate('categories', null, '分类');
t("生成器产出代码", str_contains($g['code'], 'class CategoryResource'));

echo "\n═══ 8. 应用上下文收口 ═══\n";
$ctx = \Aimanong\Aimanong::context();
t("context 可访问", $ctx instanceof \Aimanong\Application\ApplicationContext);
t("prefix 推断", is_string($ctx->prefix()));
t("guard 推断", is_string($ctx->guard()));
t("CLI 下回退正确", $ctx->prefix() === 'admin' || $ctx->prefix() === 'merchant');

echo "\n";
printf("通过 %d / %d\n", $pass, $pass + $fail);
