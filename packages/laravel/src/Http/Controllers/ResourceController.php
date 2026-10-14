<?php

declare(strict_types=1);

namespace Aimanong\Http\Controllers;

use Aimanong\Aimanong;
use Aimanong\Auth\PermissionGate;
use Aimanong\Contracts\Repository;
use Aimanong\Repository\EloquentRepository;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Schema\Compiler;
use Aimanong\Services\Exporter;
use Aimanong\Services\TreeBuilder;
use Aimanong\Support\Drivers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
        $this->authorize_($uri, 'index');

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

        // 关联列需要预加载（避免 N+1）
        $relations = [];

        foreach ($node->columns as $c) {
            $path = str_contains($c->name, '.') ? $c->name : null;

            if ($path !== null) {
                $relations[] = $path;
            }
        }

        $params = [
            'keyword' => $request->query('keyword'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => $perPage,
            'searchable' => $searchable,
            'filters' => $request->query('filters'),
            'relations' => $relations,
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
        $this->authorize_($uri, 'show');

        $node = $this->resolve($uri);

        $model = $this->repository($node)->find($id);

        // 回填多对多关联值，供编辑表单预选
        $this->loadRelations($node, $model);

        return response()->json(['data' => $model]);
    }

    /**
     * 分离多对多字段。
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, array<int, mixed>>}
     */
    protected function splitRelationFields(ResourceNode $node, array $data): array
    {
        /** @var array<string, string> $relationFields */
        $relationFields = $node->meta['relationFields'] ?? [];
        $relations = [];

        foreach ($relationFields as $field => $relation) {
            if (array_key_exists($field, $data)) {
                $relations[$relation] = array_values(array_filter(
                    (array) $data[$field],
                    static fn ($v): bool => $v !== null && $v !== ''
                ));
            }

            // 关联字段不是真实列，必须从主表数据里剔除
            unset($data[$field]);
        }

        return [$data, $relations];
    }

    /**
     * 同步多对多关联。
     *
     * @param  array<string, array<int, mixed>>  $relations
     */
    protected function syncRelations(Model $model, array $relations): void
    {
        foreach ($relations as $relation => $ids) {
            if (method_exists($model, $relation)) {
                $model->{$relation}()->sync($ids);
            }
        }
    }

    /**
     * 预加载关联值（编辑表单回填用）。
     */
    protected function loadRelations(ResourceNode $node, Model $model): void
    {
        /** @var array<string, string> $relationFields */
        $relationFields = $node->meta['relationFields'] ?? [];

        if ($relationFields === []) {
            return;
        }

        $model->load(array_values($relationFields));
    }

    /**
     * 新增。校验规则来自 Schema。
     */
    public function store(Request $request, string $uri): JsonResponse
    {
        $this->authorize_($uri, 'create');

        $node = $this->resolve($uri);

        $data = $this->validateRequest($request, $node, true);

        /*
         * 关联字段（multiSelect->relation()）不是真实列，必须：
         *   1. 从主表数据里剔除，否则 insert 会带上不存在的列
         *   2. 在主表写入拿到主键后，sync 中间表
         *
         * ⚠️ 这两步曾经只是"定义在那里"从未被调用 ——
         * 于是 multiSelect()->relation() 变成「声明了但写不进去」的幽灵能力，
         * 而且完全不报错：表单提交成功、列表看不出区别。
         * demo 里的多对多标签不得不自己写模型事件绕过（见 CmsArticle 注释）。
         */
        [$data, $relations] = $this->splitRelationFields($node, $data);

        try {
            $model = $this->repository($node)->create($data);
        } catch (QueryException $e) {
            return $this->handleConstraintViolation($e, $node);
        }

        if ($relations !== []) {
            $this->syncRelations($model, $relations);
        }

        return response()->json(['data' => $model], 201);
    }

    /**
     * 更新。
     */
    public function update(Request $request, string $uri, int|string $id): JsonResponse
    {
        $this->authorize_($uri, 'update');

        $node = $this->resolve($uri);

        $data = $this->validateRequest($request, $node);

        [$data, $relations] = $this->splitRelationFields($node, $data);

        try {
            $model = $this->repository($node)->update($id, $data);
        } catch (QueryException $e) {
            return $this->handleConstraintViolation($e, $node);
        }

        if ($relations !== []) {
            $this->syncRelations($model, $relations);
        }

        return response()->json(['data' => $model]);
    }

    /**
     * 删除。
     */
    public function destroy(Request $request, string $uri, int|string $id): JsonResponse
    {
        $this->authorize_($uri, 'destroy');

        $node = $this->resolve($uri);

        $this->repository($node)->delete($id);

        return response()->json(null, 204);
    }

    /**
     * 批量操作。
     *
     * ## 为什么只接受已声明的动作
     *
     * `$grid->batchActions([...])` 是**声明**。若后端接受任意动作名，
     * 就会出现「没声明也能调」的隐式行为 —— 与框架「低歧义、
     * 零隐式魔法」的定位冲突，也让权限边界变得不可推理。
     *
     * 未声明的动作返回 403 + 明确提示，而不是默默忽略。
     *
     * ## 为什么是「部分成功」而不是全或无
     *
     * 一次删 50 条，其中 3 条被外键约束拦住 —— 全部回滚对用户更糟：
     * 他得自己找出是哪 3 条。这里逐条执行并回报失败明细。
     */
    public function batch(Request $request, string $uri): JsonResponse
    {
        $node = $this->resolve($uri);

        /** @var array{action: string, ids: array<int, mixed>} $data */
        $data = $request->validate([
            'action' => ['required', 'string'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required'],
        ]);

        /** @var array<int, string> $declared */
        $declared = is_array($node->meta['batchActions'] ?? null)
            ? $node->meta['batchActions']
            : [];

        if (! in_array($data['action'], $declared, true)) {
            return response()->json([
                'error' => 'BATCH_ACTION_NOT_DECLARED',
                'message' => "Resource [{$uri}] 未声明批量动作 [{$data['action']}]",
                'hint' => $declared === []
                    ? '在 grid() 中调用 $grid->batchActions([\'delete\', \'export\']) 声明'
                    : '已声明：'.implode(', ', $declared),
            ], 403);
        }

        return match ($data['action']) {
            'delete' => $this->batchDelete($uri, $node, $data['ids']),
            default => response()->json([
                'error' => 'BATCH_ACTION_NOT_IMPLEMENTED',
                'message' => "批量动作 [{$data['action']}] 尚未实现",
            ], 501),
        };
    }

    /**
     * 批量删除。
     *
     * @param  array<int, mixed>  $ids
     */
    protected function batchDelete(string $uri, ResourceNode $node, array $ids): JsonResponse
    {
        // 权限复用单条删除 —— 能删一条就该能删一批，反之亦然
        $this->authorize_($uri, 'destroy');

        $repository = $this->repository($node);
        $deleted = 0;
        $failed = [];

        foreach ($ids as $id) {
            try {
                $repository->delete(is_int($id) || is_string($id) ? $id : (string) $id);
                $deleted++;
            } catch (\Throwable $e) {
                $failed[] = [
                    'id' => $id,
                    'message' => Str::limit($e->getMessage(), 120),
                ];
            }
        }

        return response()->json([
            'deleted' => $deleted,
            'failed' => $failed,
        ]);
    }

    /**
     * 把数据库约束冲突转成友好的 422。
     *
     * 第五轮 AI 实测发现：重复的唯一值会返回 500（服务器错误），
     * 而用户看到 500 不会知道"是编码重复了"。
     * 这里识别唯一索引冲突，返回 422 + 明确提示。
     */
    protected function handleConstraintViolation(QueryException $e, ?ResourceNode $node = null): JsonResponse
    {
        $message = $e->getMessage();

        /*
         * NOT NULL 违约。
         *
         * 真实场景验证（CRM 商机的「赢单率」滑块）踩到：
         * 数值字段没填时是空串，经 ConvertEmptyStringsToNull 变成 null，
         * 直接撞 NOT NULL。原先这里把原始 SQL 抛给用户 ——
         * 「NOT NULL constraint failed: crm_opportunities.probability」
         * 对使用者完全不构成指引。
         *
         * 现在指出**是哪个字段**，并给出两种改法。
         */
        if (str_contains($message, 'NOT NULL constraint failed')
            || str_contains($message, 'Column \'') && str_contains($message, 'cannot be null')) {
            $column = $this->notNullColumn($message);

            return response()->json([
                'message' => $column !== null
                    ? "字段「{$this->fieldLabel($node, $column)}」不能为空。"
                    : '有必填字段没有填写。',
                'error' => 'NOT_NULL_VIOLATION',
                'field' => $column,
                'hint' => $column !== null
                    ? "在 form() 中为 {$column} 声明 ->default(0)（数值）或 ->required()，"
                        .'数据库该列也不允许为空'
                    : '检查数值类字段是否被留空',
            ], 422);
        }

        $isUnique = str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'unique constraint')
            || str_contains($message, '1062');

        if (! $isUnique) {
            throw $e; // 不是唯一约束问题，交给框架的异常处理
        }

        // 尽量从错误信息里提取冲突的字段
        $field = null;
        if (preg_match('/UNIQUE constraint failed: [\w.]+\.(\w+)/', $message, $m)) {
            $field = $m[1];
        } elseif (preg_match("/Duplicate entry '.*' for key '([^']+)'/", $message, $m)) {
            $field = $m[1];
        }

        /*
         * ⚠️ hint 不能推荐裸的 `unique:表名,字段`。
         *
         * ## 回归（第六轮 AI 实测，父 Agent 独立复现）
         *
         * 原 hint 写的是「声明 ->rules('unique:表名,{$field}') 可在提交前校验」。
         * AI 照着做了，结果**编辑任何字段都会 422「值已存在」** ——
         * 因为 `unique` 规则会把「记录自己」也算作冲突，而框架的表单
         * 声明是静态的，拿不到当前记录 id，写不出 `->ignore($id)`。
         *
         * 实测对照：
         *   裸 unique:equipment_repairs,repair_no  → 编辑自己 = ❌ 已存在
         *   Rule::unique(...)->ignore($id)         → 编辑自己 = ✅ 通过
         *
         * 也就是说：这条 hint 会把用户**从「能编辑」引导到「不能编辑」**。
         * 对一个以「AI 一次改对」为卖点的框架，主动教错用法是最坏的情况。
         */
        return response()->json([
            'message' => $field !== null
                ? "{$field} 的值已存在，请换一个"
                : '数据违反唯一约束，请检查是否有重复值',
            'error' => 'UNIQUE_CONSTRAINT_VIOLATION',
            'field' => $field,
            'hint' => $field !== null
                ? '数据库唯一索引已正确拦截重复值，无需额外处理。'
                    .'若要「提交前」就提示：该字段的 ->rules() 里**不要**写裸的 '
                    ."unique:表名,{$field} —— 它会让编辑时记录与自己冲突；"
                    .'本框架的表单声明拿不到当前记录 id，写不出 ignore()。'
                : '数据库唯一索引已拦截重复值；不建议在 ->rules() 里补 unique 规则',
            'note' => '数据库唯一约束是最终防线；表单层预校验对「更新」场景'
                .'需要当前记录 id，当前声明式 API 无法表达，故不推荐。',
        ], 422);
    }

    /**
     * 从 NOT NULL 报错里抠出列名。
     */
    protected function notNullColumn(string $message): ?string
    {
        if (preg_match('/NOT NULL constraint failed:\s*[\w.]*?(\w+)$/m', $message, $m) === 1
            || preg_match('/Column \'(\w+)\' cannot be null/', $message, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * 列名 → 表单字段的中文标签（拿不到就用列名）。
     */
    protected function fieldLabel(?ResourceNode $node, string $column): string
    {
        if ($node !== null) {
            foreach ($node->fields as $field) {
                if ($field->name === $column) {
                    return $field->label !== '' ? $field->label : $column;
                }
            }
        }

        return $column;
    }

    /**
     * 导出 CSV。
     *
     * 复用列表接口的查询参数 —— 导出的内容与用户看到的列表一致。
     */
    public function export(Request $request, string $uri): StreamedResponse|JsonResponse
    {
        $this->authorize_($uri, 'export');
        $node = $this->resolve($uri);

        if (! ($node->meta['exportable'] ?? false)) {
            return response()->json([
                'error' => 'EXPORT_NOT_ENABLED',
                'message' => "Resource [{$uri}] 未开启导出",
                'hint' => '在 grid() 中调用 $grid->export(); 开启',
            ], 403);
        }

        $searchable = array_values(array_map(
            fn ($c): string => $c->name,
            array_filter($node->columns, fn ($c): bool => $c->searchable)
        ));

        // 与列表接口完全相同的参数处理
        $params = [
            'keyword' => $request->query('keyword'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'searchable' => $searchable,
            'filters' => $request->query('filters'),
            /*
             * 批量导出选中项：?ids[]=1&ids[]=2
             *
             * 传了就只导这些行，不传则导全部（与列表参数一致）。
             * 「导出选中」的参数化比新开端点更好 —— 导出逻辑、列格式化、
             * 权限校验全都复用同一份，不会出现两套行为。
             */
            'ids' => $request->query('ids'),
        ];

        return (new Exporter)->csv($node, $this->repository($node), $params);
    }

    /**
     * 树形数据。
     *
     * 返回嵌套结构，供前端渲染树。
     */
    public function tree(Request $request, string $uri): JsonResponse
    {
        $node = $this->resolve($uri);
        $config = $node->meta['tree'] ?? null;

        if ($config === null) {
            return response()->json([
                'error' => 'NOT_A_TREE',
                'message' => "Resource [{$uri}] 不是树形结构",
                'hint' => '在该 Resource 中实现 tree() 方法，例如 $tree->parentColumn(\'parent_id\');',
            ], 403);
        }

        $idColumn = $this->idColumn($node);

        $rows = $node->model::query()
            ->orderBy($config['orderColumn'])
            ->get()
            ->toArray();

        $builder = new TreeBuilder;

        // 循环引用会导致无限递归，必须先检测
        $cycles = $builder->detectCycles($rows, [
            'parentColumn' => $config['parentColumn'],
            'idColumn' => $idColumn,
        ]);

        if ($cycles !== []) {
            return response()->json([
                'error' => 'CIRCULAR_REFERENCE',
                'message' => '数据存在循环引用，无法构建树',
                'cycles' => $cycles,
                'hint' => '请修正这些节点的 parent 关系',
            ], 422);
        }

        return response()->json([
            'data' => $builder->build($rows, [
                'parentColumn' => $config['parentColumn'],
                'orderColumn' => $config['orderColumn'],
                'idColumn' => $idColumn,
            ]),
        ]);
    }

    /**
     * 移动树节点。
     *
     * 会校验目标位置合法性 —— 不能把节点移到自己的子孙下。
     */
    public function move(Request $request, string $uri, int|string $id): JsonResponse
    {
        $node = $this->resolve($uri);
        $config = $node->meta['tree'] ?? null;

        if ($config === null) {
            return response()->json([
                'error' => 'NOT_A_TREE',
                'message' => "Resource [{$uri}] 不是树形结构",
            ], 403);
        }

        $parentInput = $request->input('parent_id');
        $newParent = ($parentInput === null || $parentInput === '' || $parentInput === '0')
            ? null
            : $parentInput;

        $idColumn = $this->idColumn($node);

        $rows = $node->model::query()->get()->toArray();

        $check = (new TreeBuilder)->canMove($rows, [
            'parentColumn' => $config['parentColumn'],
            'idColumn' => $idColumn,
        ], $id, $newParent);

        if (! $check['ok']) {
            return response()->json([
                'error' => 'INVALID_MOVE',
                'message' => $check['reason'] ?? '不允许的移动',
            ], 422);
        }

        $model = $node->model::query()->find($id);

        if ($model === null) {
            return response()->json(['error' => 'NOT_FOUND', 'message' => "记录 {$id} 不存在"], 404);
        }

        $data = [$config['parentColumn'] => $newParent];

        $orderInput = $request->input('sort');
        if ($orderInput !== null) {
            $data[$config['orderColumn']] = $orderInput;
        }

        $model->update($data);

        return response()->json(['data' => $model->fresh(), 'message' => '移动成功']);
    }

    /**
     * 取模型的主键列名。
     */
    protected function idColumn(ResourceNode $node): string
    {
        /** @var Model $instance */
        $instance = new $node->model;

        return $instance->getKeyName();
    }

    /**
     * 校验。规则直接从编译产物取，保证与表单声明永远一致。
     *
     * @return array<string, mixed>
     */
    protected function validateRequest(Request $request, ResourceNode $node, bool $isCreate = false): array
    {
        /** @var array<string, mixed> $rules */
        $rules = $node->meta['rules'] ?? [];

        /*
         * ── 第一步：先剔除「留空即不提交」的字段 ──
         *
         * ⚠️ 顺序至关重要：必须在 validate() **之前**。
         *
         * 最初写成「先校验、再剔除」，结果编辑用户时把密码框留空，
         * min:6 直接对着空串报错「密码 不能少于 6 个字符」——
         * 而用户的意思明明是「不改密码」。校验器看到的是空串，
         * 不是"没提交"，所以规则一定会命中。
         *
         * @var array<int, mixed> $omit
         */
        $omit = $node->meta['omitWhenEmpty'] ?? [];

        $input = $request->except(['_token', '_method']);

        foreach ($omit as $name) {
            if (! is_string($name)) {
                continue;
            }

            $value = $input[$name] ?? null;

            if ($value === null || $value === '' || $value === []) {
                unset($input[$name]);
            }
        }

        /*
         * ── 第二步：仅新增时必填的字段（典型：用户表单的密码框）──
         *
         * 编辑时必须放行 —— 用户留空表示「不改密码」，
         * 若这里仍要求 required，管理员就永远改不了别的字段。
         */
        if ($isCreate) {
            /** @var array<int, mixed> $requiredOnCreate */
            $requiredOnCreate = $node->meta['requiredOnCreate'] ?? [];

            foreach ($requiredOnCreate as $name) {
                if (! is_string($name) || ! isset($rules[$name])) {
                    continue;
                }

                /** @var array<int, mixed>|string $current */
                $current = $rules[$name];
                $list = is_array($current) ? $current : explode('|', (string) $current);

                if (! in_array('required', $list, true)) {
                    array_unshift($list, 'required');
                }

                $rules[$name] = $list;
            }
        }

        /*
         * 用剔除后的输入替换请求体，再校验。
         *
         * 关键：不能直接用 validate() 的返回值 ——
         * 它只返回"有校验规则"的字段，会把未声明规则的字段静默丢弃，
         * 导致写入不完整。因此校验后仍以完整输入为准。
         */
        $request->replace($input);

        if ($rules !== []) {
            // 校验失败时抛 ValidationException → 422
            $request->validate($rules);
        }

        return $input;
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

        /*
         * 跨后台访问保护：不在当前后台的 Resource 一律 404。
         *
         * ⚠️ **必须是 404，不能是 403。**
         *
         * 403 等于告诉对方「这个资源存在，只是你没权限」——
         * 那是把后台结构泄露出去了。404 让它看起来就像不存在，
         * 与「真的没注册」无法区分。
         */
        $apps = Drivers::application();

        if ($apps->enabled() && ! Aimanong::registry()->belongsTo($uri, $apps->current())) {
            abort(404, "Resource [{$uri}] 未注册");
        }

        return $this->node = (new Compiler)->compile($class);
    }

    /**
     * 权限判定。
     *
     * 每个 Resource 自动有 6 个节点（index/show/create/update/destroy/export），
     * 由 Resource 注册时生成，无需手写。
     *
     * 未启用 RBAC 时直接放行（保留灵活性）。
     *
     * @throws AccessDeniedHttpException
     */
    protected function authorize_(string $uri, string $action): void
    {
        /*
         * ⚠️ **归属校验必须排在权限校验之前**（返回 404 而不是 403）。
         *
         * `authorize_()` 在 `resolve()` 之前调用，所以归属检查只写在
         * `resolve()` 里是**轮不到**的：代理商的角色没有
         * `agent.orders.index` 这个节点，会先被权限关拦下变成 403。
         *
         * 而 403 等于告诉对方「这个资源存在，只是你没权限」——
         * 那是把后台结构泄露出去。404 才与「真的没注册」不可区分。
         *
         * ⚠️ 这个顺序问题是**在 ThinkPHP 侧先踩到**的（那边断言期望 404
         * 却拿到 403），回头检查发现 Laravel 侧同样中招 ——
         * 两边各写一遍的代码，同一类 bug 也会各犯一遍。
         */
        $apps = Drivers::application();

        if ($apps->enabled() && ! Aimanong::registry()->belongsTo($uri, $apps->current())) {
            abort(404, "Resource [{$uri}] 未注册");
        }

        /*
         * 只读校验**必须在 RBAC 判断之前**。
         *
         * RBAC 默认关闭时 authorize_ 会直接放行；若把只读校验写在后面，
         * 未启用 RBAC 的项目就能随便改审计日志。
         * 「只读」是资源自身的性质，与权限系统无关。
         */
        $this->assertWritable($uri, $action);

        if (! PermissionGate::enabled()) {
            return;
        }

        $slug = PermissionGate::slug($uri, $action);

        if (! PermissionGate::check($slug)) {
            throw new AccessDeniedHttpException(
                "没有权限执行 {$slug}。请让管理员分配「"
                .PermissionGate::actionLabel($action).'」权限。'
            );
        }
    }

    /**
     * 只读资源拒绝一切写操作。
     *
     * @throws AccessDeniedHttpException
     */
    protected function assertWritable(string $uri, string $action): void
    {
        if (! in_array($action, ['create', 'update', 'destroy'], true)) {
            return;
        }

        $node = $this->resolve($uri);

        if (! ($node->meta['readonly'] ?? false)) {
            return;
        }

        throw new AccessDeniedHttpException(
            "「{$node->label}」是只读资源（Resource::readonly() 返回 true），"
            .'只能由系统写入，不允许通过后台接口修改。'
        );
    }

    /**
     * 取数据源。
     *
     * **优先从容器解析契约** —— 这样用户可以通过
     * `$this->app->bind(Repository::class, MyRepository::class)`
     * 替换数据源（文档承诺的能力）。
     *
     * 只有在容器没有绑定时才回退到内置实现。
     * 此前硬编码 new EloquentRepository()，导致 HTTP 路径下
     * 绑定完全不生效（真实场景验证发现）。
     */
    protected function repository(ResourceNode $node): EloquentRepository
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $node->model;

        // 1) 用户绑定了契约 → 用它（实例或类名都支持）
        if (app()->bound(Repository::class)) {
            $resolved = app(Repository::class);

            if ($resolved instanceof EloquentRepository) {
                return $resolved;
            }

            // 契约的其它实现：包一层适配器保证返回类型兼容
            return EloquentRepository::fromContract($resolved, $modelClass);
        }

        // 2) 回退到内置实现
        return new EloquentRepository($modelClass);
    }
}
