<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers\Ai;

use Aimanong\Ai\Capabilities;
use Aimanong\Ai\Verifier;
use Aimanong\Aimanong;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\AiPromptEmitter;
use Aimanong\Schema\Emitters\OpenApiEmitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 自省 API。
 *
 * 让 AI 直接询问"你能做什么"，而不是读文档猜。
 *
 * 安全：默认仅 local / debug 开启；生产需配置
 * AIMANONG_AI_TOKEN 并在请求中携带。
 */
class IntrospectionController extends Controller
{
    public function __construct()
    {
        $this->middleware = [];
        $this->guard();
    }

    /**
     * 全部能力清单。
     */
    public function capabilities(): JsonResponse
    {
        return response()->json((new Capabilities)->toArray());
    }

    /**
     * 某个 Resource 的完整 Schema。
     */
    public function schema(Request $request, string $uri): JsonResponse
    {
        $class = Aimanong::registry()->find($uri);

        if ($class === null) {
            return response()->json([
                'error' => 'RESOURCE_NOT_FOUND',
                'message' => "Resource [{$uri}] 未注册",
                'available' => array_keys(Aimanong::registry()->all()),
            ], 404);
        }

        $node = (new Compiler)->compile($class);

        return response()->json([
            'uri' => $node->uri,
            'label' => $node->label,
            'model' => $node->model,
            'grid' => array_map(fn ($c): array => $c->toArray(), $node->columns),
            'form' => array_map(fn ($f): array => $f->toArray(), $node->fields),
            'rules' => $node->meta['rules'] ?? [],
        ]);
    }

    /**
     * OpenAPI 全量文档。
     */
    public function openapi(): JsonResponse
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

        return response()->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Aimanong Admin API',
                'version' => Aimanong::version(),
            ],
            'paths' => $paths,
            'components' => ['schemas' => $schemas],
        ]);
    }

    /**
     * 给 AI 读的上下文（Markdown）。
     */
    public function context(): JsonResponse
    {
        $compiler = new Compiler;
        $emitter = new AiPromptEmitter;

        $parts = [];
        foreach (Aimanong::registry()->all() as $class) {
            $parts[] = $emitter->emit($compiler->compile($class));
        }

        return response()->json([
            'format' => 'markdown',
            'content' => implode("\n\n", $parts),
            'catalog' => $emitter->emitFieldCatalog(),
        ]);
    }

    /**
     * 校验一段 Resource 声明是否合法。
     *
     * 这是 AI 的自检入口：生成后先来验证，不必等人发现。
     */
    public function verify(Request $request): JsonResponse
    {
        $class = $request->input('resource');

        if (! is_string($class) || $class === '') {
            return response()->json([
                'error' => 'MISSING_PARAMETER',
                'message' => '缺少 resource 参数',
                'example' => '{"resource": "App\\\\Aimanong\\\\UserResource"}',
            ], 422);
        }

        return response()->json((new Verifier)->verify($class));
    }

    /**
     * 可复制代码片段。
     */
    public function examples(string $pattern): JsonResponse
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
            return response()->json([
                'error' => 'UNKNOWN_PATTERN',
                'message' => "未知的片段: {$pattern}",
                'available' => array_keys($examples),
            ], 404);
        }

        return response()->json([
            'pattern' => $pattern,
            'code' => $examples[$pattern],
        ]);
    }

    /**
     * 安全守卫：生产环境必须带 token。
     */
    protected function guard(): void
    {
        $enabled = config('aimanong.ai.enable');
        $isLocal = app()->environment('local');

        // 未显式配置时：local/debug 开启，其它环境关闭
        $allowed = $enabled === null
            ? ($isLocal || (bool) config('app.debug'))
            : (bool) $enabled;

        if (! $allowed) {
            abort(404);
        }

        // 生产环境需要 token
        if (! $isLocal) {
            $expected = config('aimanong.ai.token');

            $header = request()->header('X-Aimanong-Token');
            $query = request()->query('token');

            $given = is_string($header) ? $header : (is_string($query) ? $query : '');

            if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $given)) {
                abort(401, 'Aimanong AI 接口需要有效 token');
            }
        }
    }
}
