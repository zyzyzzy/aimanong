<?php

declare(strict_types=1);

namespace Aimanong\Ai;

use Aimanong\Aimanong;
use Aimanong\Menu\MenuRegistry;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\AiPromptEmitter;
use Aimanong\Schema\Emitters\OpenApiEmitter;

/**
 * 自省接口的**框架无关实现**。
 *
 * ## 为什么抽到 core
 *
 * 这一段原来整个长在 Laravel 的 `IntrospectionController` 里（433 行），
 * 而它**几乎完全不依赖 Laravel**：
 *
 *   · Compiler / OpenApiEmitter / AiPromptEmitter / Verifier / MenuRegistry
 *     —— 全在 core
 *   · `Capabilities` —— 本轮已挪进 core
 *   · 唯一用到的 Laravel 东西是 `response()->json()` 和 `$request->input()`
 *
 * 于是「给 ThinkPHP 也补一份」这件事，抄一遍就是**再写 433 行**，
 * 而两份拷贝必然漂移 —— 本项目最贵的几类 bug 全是这么来的
 * （跨应用 403/404 两端不一致、MCP 工具名 103 处写错、
 * `IMPLEMENTED` 常量与类注释对不上）。
 *
 * 所以：**逻辑放这里，两个驱动只做「取参 → 调用 → 包成 JSON」**。
 *
 * ## 返回值约定
 *
 * 每个方法返回 `['status' => int, 'body' => array]`，
 * 驱动层一行映射即可（`response()->json($r['body'], $r['status'])` /
 * `json($r['body'], $r['status'])`）。这样状态码也由 core 决定，
 * 不会出现「同一个错误两端状态码不同」。
 */
final class Introspection
{
    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function capabilities(): array
    {
        return ['status' => 200, 'body' => (new Capabilities)->toArray()];
    }

    /**
     * 某个 Resource 的完整 Schema。
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function schema(string $uri): array
    {
        $class = Aimanong::registry()->find($uri);

        if ($class === null) {
            return ['status' => 404, 'body' => [
                'error' => 'RESOURCE_NOT_FOUND',
                'message' => "Resource [{$uri}] 未注册",
                'available' => array_keys(Aimanong::registry()->all()),
            ]];
        }

        $node = (new Compiler)->compile($class);

        /*
         * 结构与 schema.json 保持一致。
         *
         * 第五轮 AI 实测发现：此前 grid 返回扁平列数组，丢掉了
         * perPage / exportable / tree；form 丢掉了 stepped / steps。
         * 导致 AI 无法通过自省接口自查这些能力 —— 修好这一点。
         */
        $fields = array_map(fn ($f): array => $f->toArray(), $node->fields);

        $steps = [];
        foreach ($node->meta['steps'] ?? [] as $step) {
            $names = [];

            foreach ($step['fields'] ?? [] as $sf) {
                $names[] = is_array($sf) ? ($sf['name'] ?? null) : $sf;
            }

            $steps[] = [
                'title' => $step['title'] ?? '',
                'fields' => array_values(array_filter($names)),
            ];
        }

        return ['status' => 200, 'body' => [
            'uri' => $node->uri,
            'label' => $node->label,
            'model' => $node->model,
            'grid' => [
                'perPage' => $node->meta['perPage'] ?? 20,
                /*
                 * 列表展示形态（table / list / cards）。
                 *
                 * ⚠️ 这里是**第三个**独立组装 grid 段的地方
                 * （另两处是 Compiler 与 JsonSchemaEmitter）。
                 * 漏掉它 → 自省接口谎报「没有 mode」，而页面确实按 mode 渲染 ——
                 * AI 自查时看到的与实际不符，比完全不知道更糟。
                 */
                'mode' => $node->meta['mode'] ?? 'table',
                'exportable' => $node->meta['exportable'] ?? false,
                'exportColumns' => array_column($node->meta['exportColumns'] ?? [], 'name'),
                'tree' => $node->meta['tree'] ?? null,
                'columns' => array_map(fn ($c): array => $c->toArray(), $node->columns),
            ],
            'form' => [
                'fields' => $fields,
                'stepped' => $node->meta['stepped'] ?? false,
                'steps' => $steps,
            ],
            'show' => [
                'fields' => array_map(fn ($f): array => $f->toArray(), $node->detailFields),
                /*
                 * 详情页结构。kind=flat 表示没声明 layout()。
                 * 与 JsonSchemaEmitter 保持同一形状 —— 两处不一致
                 * 会让 AI 自省到的结构与页面实际不符。
                 */
                'layout' => $node->meta['layout'] ?? ['kind' => 'flat', 'groups' => []],
            ],
            'rules' => $node->meta['rules'] ?? [],
            'capabilities' => [
                'is_tree' => is_array($node->meta['tree'] ?? null),
                'is_exportable' => ($node->meta['exportable'] ?? false) === true,
                'is_stepped' => ($node->meta['stepped'] ?? false) === true,
            ],
        ]];
    }

    /**
     * OpenAPI 全量文档。
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function openapi(): array
    {
        $compiler = new Compiler;
        $emitter = new OpenApiEmitter;

        $paths = [];
        $schemas = [];

        foreach (Aimanong::registry()->all() as $class) {
            $oa = $emitter->emit($compiler->compile($class));
            $paths = array_merge($paths, $oa['paths']);
            $schemas = array_merge($schemas, $oa['components']['schemas']);
        }

        return ['status' => 200, 'body' => [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Aimanong Admin API',
                'version' => Aimanong::version(),
            ],
            'paths' => $paths,
            'components' => ['schemas' => $schemas],
        ]];
    }

    /**
     * 给 AI 读的上下文（Markdown）。
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function context(): array
    {
        $compiler = new Compiler;
        $emitter = new AiPromptEmitter;

        $parts = [];
        foreach (Aimanong::registry()->all() as $class) {
            $parts[] = $emitter->emit($compiler->compile($class));
        }

        return ['status' => 200, 'body' => [
            'format' => 'markdown',
            'content' => implode("\n\n", $parts),
            'catalog' => $emitter->emitFieldCatalog(),
        ]];
    }

    /**
     * 校验一段 Resource 声明是否合法。
     *
     * 这是 AI 的自检入口：生成后先来验证，不必等人发现。
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function verify(mixed $class): array
    {
        if (! is_string($class) || $class === '') {
            return ['status' => 422, 'body' => [
                'error' => 'MISSING_PARAMETER',
                'message' => '缺少 resource 参数',
                'example' => '{"resource": "App\\\\Aimanong\\\\UserResource"}',
            ]];
        }

        return ['status' => 200, 'body' => (new Verifier)->verify($class)];
    }

    /**
     * 已声明的菜单树（**全量**，不做权限过滤）。
     *
     * `/__ai/*` 这组路由**没有登录上下文**，所以这里拿不到「当前用户」——
     * 按权限过滤只会得到空数组，反而误导。
     *
     * 权限过滤发生在**渲染时**（页面侧带着真实会话），
     * 见 MenuRegistry::visible()。
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function menu(): array
    {
        return ['status' => 200, 'body' => [
            'menu' => (new MenuRegistry)->all(),
            'note' => '这是**全量**已声明菜单（本接口无登录上下文，不做权限过滤）。'
                .'某个用户实际可见的菜单在渲染时按 {uri}.index 权限过滤。',
        ]];
    }

    /**
     * 可复制代码片段。
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function examples(string $pattern): array
    {
        $examples = [
            'resource' => <<<'PHP'
<?php

namespace App\Aimanong;

use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Show\Show;

class ExampleResource extends Resource
{
    public static function model(): string
    {
        return \App\Models\Example::class;
    }

    public static function label(): string
    {
        return '示例';
    }

    public static function uri(): string
    {
        return 'examples';
    }

    public static function grid(Grid $grid): void
    {
        $grid->column('id', 'ID')->sortable();
        $grid->column('title', '标题')->searchable();
        $grid->column('created_at', '创建时间')->dateTime()->sortable();
    }

    public static function form(Form $form): void
    {
        $form->text('title')->label('标题')->required()->max(255);
        $form->select('status')->label('状态')->options([
            'active' => '启用',
            'disabled' => '禁用',
        ]);
        $form->switch('enabled')->label('是否启用');
    }

    public static function show(Show $show): void
    {
        $show->fields(['id', 'title']);
    }
}
PHP,
            'grid' => <<<'PHP'
public static function grid(Grid $grid): void
{
    $grid->column('id', 'ID')->sortable();
    $grid->column('name', '名称')->searchable();
    $grid->column('status', '状态')->map(['active' => '启用', 'disabled' => '禁用']);
    $grid->column('created_at', '创建时间')->dateTime()->sortable();
    $grid->perPage(20);
}
PHP,
            /*
             * 树形。
             *
             * ⚠️ 之前**没有这一段** —— 实测（L3 复测）一个 LLM 想用 tree()，在
             * llms.txt、capabilities.json、`/__ai/examples/tree`（404）三处
             * 都拿不到 `Tree` 类的命名空间，最后靠 `class_exists()` 反射才命中
             * `Aimanong\Tree\Tree`。片段本身就是给「不知道写什么」用的，
             * 少了 namespace 等于没用。
             */
            'tree' => <<<'PHP'
<?php

namespace App\Aimanong;

use Aimanong\Resource;
use Aimanong\Tree\Tree;   // ← 树形钩子的参数类型
use App\Models\Department;

class DepartmentResource extends Resource
{
    public static function model(): string
    {
        return Department::class;
    }

    public static function label(): string
    {
        return '部门';
    }

    public static function uri(): string
    {
        return 'departments';
    }

    /**
     * 声明为树形 —— 列表会自动按层级缩进 + 给出展开/折叠。
     *
     * 四个参数都有默认值（parent_id / name / sort / 0），可以只写用得着的：
     *   $tree->parentColumn('parent_id')->titleColumn('name')->orderColumn('sort');
     *
     * ⚠️ 树形页的列表**只请求 /tree 接口**，所以：
     *   · 关联列（`$grid->column('parent.name')`）在树形页取不到值
     *   · 需要显示父级名称时用模型访问器（见下方 parent_name）
     */
    public static function tree(Tree $tree): void
    {
        $tree->parentColumn('parent_id')
            ->titleColumn('name')
            ->orderColumn('sort');
    }

    public static function grid(\Aimanong\Grid\Grid $grid): void
    {
        $grid->column('id', 'ID');
        // 树形页**不要**用关联列 —— 改用访问器（模型里定义 parent_name 并 $appends）
        $grid->column('name', '部门名称');
        $grid->column('sort', '排序');
    }

    public static function form(\Aimanong\Form\Form $form): void
    {
        $form->text('name', '部门名称')->required();
        // 外键下拉：候选自动来自模型关联（本表**自关联**也一样）
        $form->belongsTo('parent_id', '上级部门');
    }
}
PHP,
            /*
             * 自定义批量动作的处理器。
             *
             * 与 'tree' 同样的理由：片段的用途就是「照抄」，所以必须给全
             * namespace —— 契约类在 `Aimanong\Contracts` 下。
             */
            'batch-handler' => <<<'PHP'
<?php

namespace App\Aimanong\Actions;

use Aimanong\Contracts\BatchActionHandler;   // ← 必须实现的契约

/**
 * 批量把订单标记为已发货。
 *
 * 只写业务：权限（{uri}.{动作名} 节点）、id 校验、操作日志都由框架
 * 在调用它之前处理掉，这里不需要也不应该再判一次。
 */
class ShipOrders implements BatchActionHandler
{
    /**
     * @param  string  $model  当前 Resource 的模型类名
     * @param  array<int, mixed>  $ids  被选中的主键
     * @return int  受影响行数（回给前端做提示）
     */
    public function handle(string $model, array $ids): int
    {
        return $model::whereIn('id', $ids)->update(['status' => 'shipped']);
    }
}
PHP,
            'form' => <<<'PHP'
public static function form(Form $form): void
{
    $form->text('name')->label('名称')->required()->max(255);
    $form->email('email')->label('邮箱')->required()->rules('email');
    $form->select('type')->label('类型')->options(['a' => '甲', 'b' => '乙']);
    $form->number('sort')->label('排序')->default(0);
    $form->switch('enabled')->label('启用');
}
PHP,
        ];

        if (! isset($examples[$pattern])) {
            return ['status' => 404, 'body' => [
                'error' => 'UNKNOWN_PATTERN',
                'message' => "未知的片段: {$pattern}",
                'available' => array_keys($examples),
            ]];
        }

        return ['status' => 200, 'body' => [
            'pattern' => $pattern,
            'code' => $examples[$pattern],
        ]];
    }
}
