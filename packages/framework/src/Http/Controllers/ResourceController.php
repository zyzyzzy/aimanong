<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Aimanong\Repository\EloquentRepository;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Schema\Compiler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * Resource 通用 CRUD 控制器。
 *
 * 所有行为由编译后的 Schema 驱动：
 *   - 可搜索列：从 ColumnNode->searchable 推导，无需重复声明
 *   - 校验规则：从 Form 的 rules 推导
 *
 * 因此新增一个 Resource 不需要写任何控制器代码。
 */
class ResourceController extends Controller
{
    protected ?ResourceNode $node = null;

    /**
     * 列表（分页 + 搜索 + 排序）。
     */
    public function index(Request $request, string $uri): JsonResponse
    {
        $node = $this->resolve($uri);

        $searchable = array_values(array_map(
            fn ($c): string => $c->name,
            array_filter($node->columns, fn ($c): bool => $c->searchable)
        ));

        $perPage = $request->query('per_page');

        // 请求未指定时回退到 Resource 中声明的 perPage，
        // 否则 $grid->perPage(30) 只会写进 schema.json 而 API 仍用 20，
        // 造成"校验器报已设置、功能却没生效"的假阳性。
        if (! is_numeric($perPage)) {
            $perPage = $node->meta['perPage'] ?? 20;
        }

        // 未知查询参数警告：AI 常猜错参数名（如用 order 代替 direction），
        // 原先静默忽略、返回未排序结果 —— 比报错更危险。
        $unknown = $this->unknownQueryParams($request);

        $params = [
            'keyword' => $request->query('keyword'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => $perPage,
            'searchable' => $searchable,
            'filters' => $request->query('filters'),
        ];

        $paginator = $this->repository($node)->paginate(
            $params,
            (int) ($node->meta['perPage'] ?? 20)
        );

        $response = [
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'perPage' => $paginator->perPage(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];

        if ($unknown !== []) {
            $response['warnings'] = [[
                'code' => 'UNKNOWN_QUERY_PARAM',
                'message' => '忽略了未知查询参数: '.implode(', ', $unknown),
                'supported' => ['keyword', 'sort', 'direction', 'per_page', 'filters', 'page'],
                'hint' => '排序方向参数是 direction（不是 order / sort_order）',
            ]];
        }

        return response()->json($response);
    }

    /**
     * 找出不支持的查询参数。
     *
     * 背景：AI 常猜错参数名（如用 order= 代替 direction=），
     * 原先静默忽略并返回未排序结果 —— AI 会以为排序生效了。
     *
     * @return array<int, string>
     */
    protected function unknownQueryParams(Request $request): array
    {
        $supported = ['keyword', 'search', 'sort', 'direction', 'per_page', 'filters', 'page'];

        /** @var array<string, mixed> $query */
        $query = $request->query();

        return array_values(array_diff(array_keys($query), $supported));
    }

    /**
     * 详情。
     */
    public function show(Request $request, string $uri, int|string $id): JsonResponse
    {
        $node = $this->resolve($uri);

        return response()->json([
            'data' => $this->repository($node)->find($id),
        ]);
    }

    /**
     * 新增。校验规则来自 Schema。
     */
    public function store(Request $request, string $uri): JsonResponse
    {
        $node = $this->resolve($uri);

        $data = $this->validateRequest($request, $node);

        $model = $this->repository($node)->create($data);

        return response()->json(['data' => $model], 201);
    }

    /**
     * 更新。
     */
    public function update(Request $request, string $uri, int|string $id): JsonResponse
    {
        $node = $this->resolve($uri);

        $data = $this->validateRequest($request, $node);

        $model = $this->repository($node)->update($id, $data);

        return response()->json(['data' => $model]);
    }

    /**
     * 删除。
     */
    public function destroy(Request $request, string $uri, int|string $id): JsonResponse
    {
        $node = $this->resolve($uri);

        $this->repository($node)->delete($id);

        return response()->json(null, 204);
    }

    /**
     * 校验。规则直接从编译产物取，保证与表单声明永远一致。
     *
     * @return array<string, mixed>
     */
    protected function validateRequest(Request $request, ResourceNode $node): array
    {
        $rules = $node->meta['rules'] ?? [];

        if ($rules === []) {
            return $request->all();
        }

        // 校验（失败时抛 ValidationException → 422）
        $request->validate($rules);

        /*
         * 关键：不能直接用 validate() 的返回值。
         * 它只返回"有校验规则"的字段，会把 password 等
         * 未在表单声明中出现的字段静默丢弃，导致写入失败。
         * 因此校验通过后仍以原始输入为准。
         */
        $data = $request->all();

        // 剔除 Laravel 内部字段
        unset($data['_token'], $data['_method']);

        return $data;
    }

    protected function resolve(string $uri): ResourceNode
    {
        if ($this->node !== null && $this->node->uri === $uri) {
            return $this->node;
        }

        $class = Aimanong::registry()->find($uri);

        if ($class === null) {
            abort(404, "Resource [{$uri}] 未注册");
        }

        return $this->node = (new Compiler)->compile($class);
    }

    protected function repository(ResourceNode $node): EloquentRepository
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $node->model;

        return new EloquentRepository($modelClass);
    }
}
