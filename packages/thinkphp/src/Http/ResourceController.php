<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http;

use Aimanong\Aimanong;
use Aimanong\Contracts\BatchActionHandler;
use Aimanong\Exceptions\SortColumnNotDeclaredException;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Schema\Compiler;
use Aimanong\Services\TreeBuilder;
use Aimanong\Support\Drivers;
use Aimanong\ThinkPHP\ThinkDataSource;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\db\exception\PDOException;
use think\exception\HttpException;
use think\facade\Db;
use think\Model;
use think\Request;
use think\Response;
use think\response\Json;
use think\Validate;

/**
 * 数据 API —— Resource 声明在 ThinkPHP 上的执行端。
 *
 * ## 与 Laravel 侧的关系
 *
 * 声明（Resource 的 grid/form/show）**完全同一套**，由 core 的 Compiler
 * 编译成 `ResourceNode`；差别只在这里怎么执行它。
 *
 * 本控制器覆盖核心 CRUD：index / show / store / update / destroy。
 * **尚未移植**：batch / export / tree / move（见类尾注释）。
 * 这些在 TP 侧目前**返回 501 而不是静默成功** —— 声明了却悄悄不做事，
 * 正是宪法第三条禁止的幽灵能力。
 */
final class ResourceController
{
    /**
     * 已实现的动作。未在其中的一律 501。
     *
     * @var array<int, string>
     */
    public const IMPLEMENTED = ['index', 'show', 'store', 'update', 'destroy', 'tree', 'batch'];

    protected ?ResourceNode $node = null;

    // ─────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────

    public function index(Request $request, string $uri): Response
    {
        if (($deny = $this->deny($uri, 'index')) !== null) {
            return $deny;
        }

        $node = $this->resolve($uri);

        $searchable = [];

        foreach ($node->columns as $column) {
            if ($column->searchable) {
                $searchable[] = $column->name;
            }
        }

        // 请求未指定时回退到 Resource 声明的 perPage，
        // 否则 $grid->perPage(30) 只写进 schema.json，API 仍用 20 ——
        // 校验器说「已设置」而功能没生效。
        $perPage = $request->param('per_page');
        $declared = $node->meta['perPage'] ?? 20;

        if (! is_numeric($perPage)) {
            $perPage = $declared;
        }

        $relations = [];

        foreach ($node->columns as $column) {
            if (str_contains($column->name, '.')) {
                $relations[] = $column->name;
            }
        }

        /*
         * 非法排序列必须明确报错 —— 与 Laravel 侧同一套语义。
         *
         * 原先 sort 直接透传给 order()，列不存在时的行为取决于数据库：
         * MySQL 报错、SQLite **静默接受并返回未排序数据**。
         * 后者会让调用方以为排序生效了（实测两个 AI agent 都踩到）。
         *
         * 违反宪法 ③「零隐式」：做不到就说做不到。
         */
        $sortCheck = $this->sortColumnError($node, $request);

        if ($sortCheck !== null) {
            return $sortCheck;
        }

        $page = $this->repository($node)->paginate([
            'keyword' => $this->scalar($request->param('keyword')),
            'sort' => $this->scalar($request->param('sort')),
            'direction' => $this->scalar($request->param('direction')),
            'per_page' => $perPage,
            'searchable' => $searchable,
            'filters' => $this->filters($request->param('filters')),
            'relations' => $relations,
        ], (int) $declared);

        $payload = [
            'data' => $page->items(),
            'meta' => [
                'total' => $page->total(),
                'perPage' => $page->perPage(),
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
            ],
        ];

        // 未知查询参数警告：AI 常把 direction 猜成 order，
        // 静默忽略会返回**未排序**的结果而 AI 以为排序生效了。
        $unknown = $this->unknownQueryParams($request);

        if ($unknown !== []) {
            $payload['warnings'] = [[
                'code' => 'UNKNOWN_QUERY_PARAM',
                'message' => '忽略了未知查询参数: '.implode(', ', $unknown),
                'supported' => ['keyword', 'sort', 'direction', 'per_page', 'filters', 'page'],
                'hint' => '排序方向参数是 direction（不是 order / sort_order）',
            ]];
        }

        return Response::create($payload, 'json', 200);
    }

    public function show(Request $request, string $uri, int|string $id): Response
    {
        if (($deny = $this->deny($uri, 'show')) !== null) {
            return $deny;
        }

        $node = $this->resolve($uri);

        try {
            $model = $this->repository($node)->find($id);
        } catch (ModelNotFoundException) {
            return $this->notFound($uri, $id);
        }

        return Response::create(['data' => $model], 'json', 200);
    }

    public function store(Request $request, string $uri): Response
    {
        if (($deny = $this->deny($uri, 'create')) !== null) {
            return $deny;
        }

        $node = $this->resolve($uri);

        $errors = $this->validateInput($request, $node, true);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $data = $this->payload($request);
        [$data, $relations] = $this->splitRelationFields($node, $data);

        try {
            $model = $this->repository($node)->create($data);

            if ($relations !== []) {
                $this->syncRelations($model, $relations);
            }
        } catch (PDOException|DbException $e) {
            return $this->constraintViolation($e, $node);
        }

        return Response::create(['data' => $model], 'json', 201);
    }

    public function update(Request $request, string $uri, int|string $id): Response
    {
        if (($deny = $this->deny($uri, 'update')) !== null) {
            return $deny;
        }

        $node = $this->resolve($uri);

        $errors = $this->validateInput($request, $node, false);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $data = $this->payload($request);
        [$data, $relations] = $this->splitRelationFields($node, $data);

        try {
            $model = $this->repository($node)->update($id, $data);

            if ($relations !== []) {
                $this->syncRelations($model, $relations);
            }
        } catch (ModelNotFoundException) {
            return $this->notFound($uri, $id);
        } catch (PDOException|DbException $e) {
            return $this->constraintViolation($e, $node);
        }

        return Response::create(['data' => $model], 'json', 200);
    }

    public function destroy(Request $request, string $uri, int|string $id): Response
    {
        if (($deny = $this->deny($uri, 'destroy')) !== null) {
            return $deny;
        }

        $node = $this->resolve($uri);

        try {
            $this->repository($node)->delete($id);
        } catch (ModelNotFoundException) {
            return $this->notFound($uri, $id);
        }

        return Response::create('', 'json', 204);
    }

    // ─────────────────────────────────────────────
    // 未移植的动作 —— 明确 501，不假装成功
    // ─────────────────────────────────────────────

    /**
     * 树形数据。
     *
     * 与 Laravel 侧同一份语义 —— `TreeBuilder` 在 core，两端共用同一套
     * 建树/环检测逻辑（否则「同一份声明两端行为一致」就是空话）。
     *
     * ⚠️ 权限：树形是**读全部数据**的接口，与 index 同权限。
     * Laravel 侧这里曾经漏掉权限校验，被实测出「零权限账号能读全表」。
     */
    public function tree(Request $request, string $uri): Response
    {
        if (($deny = $this->deny($uri, 'index')) !== null) {
            return $deny;
        }

        $node = $this->resolve($uri);
        $config = $node->meta['tree'] ?? null;

        if ($config === null) {
            return Response::create([
                'error' => 'NOT_A_TREE',
                'message' => "Resource [{$uri}] 不是树形结构",
                'hint' => "在该 Resource 中实现 tree() 方法，例如 \$tree->parentColumn('parent_id');",
            ], 'json', 403);
        }

        $model = $node->model;

        if (! class_exists($model)) {
            return Response::create([
                'error' => 'MODEL_NOT_FOUND',
                'message' => "Resource [{$uri}] 的模型 {$model} 不存在",
            ], 'json', 500);
        }

        // 主键：Eloquent 用 getKeyName()，TP 用 getPk()（复合主键时是数组）
        $probe = new $model;
        $pk = method_exists($probe, 'getPk') ? $probe->getPk() : null;
        $idColumn = is_string($pk) && $pk !== '' ? $pk : 'id';

        /*
         * 关联列要预加载 —— 与 Laravel 侧同一套逻辑。
         *
         * 不预加载的后果：树形页里 `$grid->column('parent.name')` 显示空白，
         * 因为树形页前端只请求本接口，拿到的是模型本体、不含关联数据。
         * 与 Laravel 侧的差异：ThinkORM 用 `with()`（同名方法）。
         */
        $relations = [];

        foreach ($node->columns as $column) {
            if (str_contains($column->name, '.')) {
                $relations[] = explode('.', $column->name)[0];
            }
        }

        $treeQuery = $model::order($config['orderColumn']);

        if ($relations !== []) {
            $treeQuery->with(array_unique($relations));
        }

        $rows = $treeQuery->select()->toArray();

        $builder = new TreeBuilder;

        // 循环引用会导致无限递归，必须先检测（与 Laravel 侧同一实现）
        $cycles = $builder->detectCycles($rows, [
            'parentColumn' => $config['parentColumn'],
            'idColumn' => $idColumn,
        ]);

        if ($cycles !== []) {
            return Response::create([
                'error' => 'CIRCULAR_REFERENCE',
                'message' => '数据存在循环引用，无法构建树',
                'cycles' => $cycles,
                'hint' => '请修正这些节点的 parent 关系',
            ], 'json', 422);
        }

        return Response::create([
            'data' => $builder->build($rows, [
                'parentColumn' => $config['parentColumn'],
                'orderColumn' => $config['orderColumn'],
                'idColumn' => $idColumn,
            ]),
        ], 'json', 200);
    }

    /**
     * 批量动作 —— 与 Laravel 侧同一套语义。
     *
     * 内置 delete + 声明式自定义动作（`$grid->batchAction(...)->handler(...)`）。
     * 自定义动作的处理器必须是 core 的 `BatchActionHandler` 实现，
     * 只拿到「模型类名 + ids」—— 权限、参数校验由框架在前面做掉。
     */
    public function batch(Request $request, string $uri): Response
    {
        if (($deny = $this->deny($uri, 'index')) !== null) {
            return $deny;
        }

        $node = $this->resolve($uri);

        $payload = $this->payload($request);
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';
        $ids = $payload['ids'] ?? null;

        if ($action === '' || ! is_array($ids) || $ids === []) {
            return Response::create([
                'error' => 'INVALID_BATCH_PAYLOAD',
                'message' => '批量动作需要 action（字符串）与非空 ids（数组）',
            ], 'json', 422);
        }

        // 动作必须**声明过** —— 不做「没声明也能调」的隐式行为
        $declared = is_array($node->meta['batchActions'] ?? null) ? $node->meta['batchActions'] : [];

        if (! in_array($action, $declared, true)) {
            return Response::create([
                'error' => 'BATCH_ACTION_NOT_DECLARED',
                'message' => "Resource [{$uri}] 未声明批量动作 [{$action}]",
                'hint' => $declared === []
                    ? "在 grid() 中声明：内置用 \$grid->batchActions(['delete'])，自定义用 \$grid->batchAction('名称','标签')->handler(处理器类)"
                    : '已声明：'.implode(', ', $declared),
            ], 'json', 403);
        }

        if ($action === 'delete') {
            return $this->batchDelete($uri, $node, $ids);
        }

        return $this->runCustomBatch($uri, $node, $action, $ids);
    }

    /**
     * 批量删除（权限复用单条删除）。
     *
     * @param  array<int, mixed>  $ids
     */
    protected function batchDelete(string $uri, ResourceNode $node, array $ids): Response
    {
        if (($deny = $this->deny($uri, 'destroy')) !== null) {
            return $deny;
        }

        $model = $node->model;
        $probe = new $model;
        $pk = method_exists($probe, 'getPk') ? $probe->getPk() : null;
        $key = is_string($pk) && $pk !== '' ? $pk : 'id';

        try {
            $affected = $model::whereIn($key, $ids)->delete();
        } catch (\Throwable $e) {
            return Response::create([
                'error' => 'BATCH_ACTION_FAILED',
                'message' => '批量删除失败：'.$e->getMessage(),
            ], 'json', 422);
        }

        return Response::create([
            'action' => 'delete',
            'affected' => $affected,
            'message' => "已删除 {$affected} 条",
        ], 'json', 200);
    }

    /**
     * 执行自定义批量动作 —— 分发到声明里引用的处理器类。
     *
     * @param  array<int, mixed>  $ids
     */
    protected function runCustomBatch(string $uri, ResourceNode $node, string $action, array $ids): Response
    {
        $declared = null;

        foreach ($node->meta['customBatchActions'] ?? [] as $custom) {
            if (is_array($custom) && ($custom['name'] ?? null) === $action) {
                $declared = $custom;
                break;
            }
        }

        if ($declared === null || ! is_string($declared['handler'] ?? null)) {
            return Response::create([
                'error' => 'BATCH_ACTION_NOT_IMPLEMENTED',
                'message' => "批量动作 [{$action}] 尚未实现",
                'hint' => '自定义动作要用 $grid->batchAction(名称, 标签)->handler(处理器类) 声明',
            ], 'json', 501);
        }

        // 自定义动作有自己的权限节点（PermissionNodes::actionsFor 会生成）
        if (($deny = $this->deny($uri, $action)) !== null) {
            return $deny;
        }

        $handlerClass = $declared['handler'];

        if (! class_exists($handlerClass)) {
            return Response::create([
                'error' => 'BATCH_HANDLER_MISSING',
                'message' => "批量动作 [{$action}] 的处理器 {$handlerClass} 不存在",
            ], 'json', 500);
        }

        try {
            /** @var BatchActionHandler $handler */
            $handler = new $handlerClass;
            $affected = $handler->handle($node->model, $ids);
        } catch (\Throwable $e) {
            return Response::create([
                'error' => 'BATCH_ACTION_FAILED',
                'message' => "批量动作 [{$action}] 执行失败：".$e->getMessage(),
            ], 'json', 422);
        }

        return Response::create([
            'action' => $action,
            'affected' => $affected,
            'message' => "已处理 {$affected} 条",
        ], 'json', 200);
    }

    public function unimplemented(Request $request, string $uri, string $action): Response
    {
        return Response::create([
            'error' => [
                'code' => 'NOT_IMPLEMENTED_ON_THINKPHP',
                'message' => "动作 [{$action}] 尚未在 ThinkPHP 驱动上实现。",
                'implemented' => self::IMPLEMENTED,
                'hint' => '这不是「没有数据」，而是「还没做」——故意返回 501 而不是空结果。',
            ],
        ], 'json', 501);
    }

    // ─────────────────────────────────────────────
    // 内部
    // ─────────────────────────────────────────────

    protected function resolve(string $uri): ResourceNode
    {
        if ($this->node !== null && $this->node->uri === $uri) {
            return $this->node;
        }

        $class = Aimanong::registry()->find($uri);

        if ($class === null) {
            throw new HttpException(404, "Resource [{$uri}] 未注册");
        }

        /*
         * 跨后台访问保护：不在当前后台的 Resource 一律 404。
         *
         * ⚠️ **必须是 404，不能是 403。**
         *
         * 403 等于告诉对方「这个资源存在，只是你没权限」——
         * 那是把后台结构泄露出去。404 让它看起来就像不存在，
         * 与「真的没注册」无法区分。（与 Laravel 侧同一套语义。）
         */
        $apps = Drivers::application();

        if ($apps->enabled() && ! Aimanong::registry()->belongsTo($uri, $apps->current())) {
            throw new HttpException(404, "Resource [{$uri}] 未注册");
        }

        return $this->node = (new Compiler)->compile($class);
    }

    protected function repository(ResourceNode $node): ThinkDataSource
    {
        /** @var class-string<Model> $model */
        $model = $node->model;

        return new ThinkDataSource($model);
    }

    /**
     * 权限判定。
     *
     * 走 `Drivers::permissions()` —— 与 Laravel 侧同一个契约，
     * TP 的实现是配置驱动的（见 ThinkPermissionChecker）。
     */
    /**
     * 权限判定。
     *
     * ⚠️ 返回一个 **JSON** 的 403，而不是抛 HttpException。
     * 抛异常会被 TP 的默认处理器渲染成 HTML 错误页 ——
     * 对一个 JSON 接口来说，调用方（前端 / AI Agent）拿到一坨 HTML
     * 是没法处理的。实测踩到。
     *
     * @return Response|null null = 放行
     */
    protected function deny(string $uri, string $action): ?Response
    {
        /*
         * ⚠️ **归属校验必须排在权限校验之前**（返回 404 而不是 403）。
         *
         * `deny()` 在 `resolve()` 之前被调用，所以归属检查只写在
         * `resolve()` 里是**轮不到**的：代理商的角色没有
         * `agent.customers.index` 这个节点，会先被权限关拦下变成 403。
         *
         * 而 403 等于告诉对方「这个资源存在，只是你没权限」——
         * 那是把后台结构泄露出去。404 才与「真的没注册」不可区分。
         *
         * ⚠️ 这个顺序问题实测踩到：断言期望 404，实际拿到 403。
         */
        $apps = Drivers::application();

        if ($apps->enabled() && ! Aimanong::registry()->belongsTo($uri, $apps->current())) {
            throw new HttpException(404, "Resource [{$uri}] 未注册");
        }

        /*
         * 只读校验**必须在 RBAC 之前**。
         *
         * RBAC 默认关闭时权限判定直接放行；若把只读校验写在后面，
         * 未启用 RBAC 的项目就能随便改只读资源。「只读」是资源自身的
         * 性质，与权限系统无关 —— Laravel 侧也是这个顺序。
         *
         * ⚠️ 本次全面验收发现：TP 侧此前**完全没有**这道校验，
         * 只读资源会被真的写库；而共享的 OpenApiEmitter 按 readonly
         * 省略了写接口 —— 产物说不许写、接口却接受写。
         */
        if (in_array($action, ['create', 'update', 'destroy'], true)) {
            $node = $this->resolve($uri);

            if ((bool) ($node->meta['readonly'] ?? false)) {
                return Response::create([
                    'error' => [
                        'code' => 'RESOURCE_READONLY',
                        'message' => "「{$node->label}」是只读资源（Resource::readonly()），"
                            .'只能由系统写入，不允许通过后台接口修改。',
                    ],
                ], 'json', 403);
            }
        }

        $checker = Drivers::permissions();
        $slug = $checker->slug($uri, $action);

        if ($checker->check($slug)) {
            return null;
        }

        return Response::create([
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => "无权限：{$slug}",
                'slug' => $slug,
                'hint' => '权限由「用户 → 角色 → 权限节点」判定；超管角色绕过全部判定。',
            ],
        ], 'json', 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        /** @var array<string, mixed> $all */
        $all = $request->param();

        unset($all['_token'], $all['_method'], $all['_']);

        return $all;
    }

    /**
     * 用 ThinkPHP 的 Validate 执行声明里的规则。
     *
     * ## 为什么不能「不支持的规则就跳过」
     *
     * `$form->text('x')->required()` 是**声明**。若 TP 侧默默不校验，
     * 这个声明就成了幽灵能力 —— 表单写着必填、接口却照收空值。
     * 所以不认识的规则**直接抛异常**，把问题摆到台面上。
     *
     * @return array<string, string>
     */
    protected function validateInput(Request $request, ResourceNode $node, bool $isCreate): array
    {
        $meta = $node->meta;
        /** @var array<string, mixed> $rules */
        $rules = is_array($meta['rules'] ?? null) ? $meta['rules'] : [];

        if ($rules === []) {
            return [];
        }

        $data = $this->payload($request);

        // ① 留空即不提交的字段（典型：编辑时留空 = 不改密码）。
        //    必须在校验**之前**剔除，否则 min:6 会对着空串报错。
        foreach ((array) ($meta['omitWhenEmpty'] ?? []) as $name) {
            if (is_string($name) && ($data[$name] ?? null) === '') {
                unset($data[$name]);
            }
        }

        // ② 仅新增时必填的字段：编辑时留空表示「不改」，必须放行
        if (! $isCreate) {
            foreach ((array) ($meta['requiredOnCreate'] ?? []) as $name) {
                if (is_string($name)) {
                    $rules[$name] = $this->dropRequired($this->normalizeRule($rules[$name] ?? ''));
                }
            }
        }

        /*
         * 仅新增时必填的字段（典型：用户表单的密码框）。
         *
         * ⚠️ 本次全面验收发现：TP 侧此前只处理了「编辑时 drop require」，
         * **新增时什么都不加** —— 于是 POST 不带密码不会 422，
         * 直接撞上 NOT NULL → 未捕获异常 → 500。
         * 而 Laravel 是 422「密码 不能为空」。
         */
        if ($isCreate) {
            foreach ((array) ($meta['requiredOnCreate'] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $existing = $this->normalizeRule($rules[$name] ?? '');
                    $rules[$name] = 'required'.($existing !== '' ? '|'.$existing : '');
                }
            }
        }

        $translated = [];
        $messages = [];
        /** @var array<string, array{table: string, column: string}> $existsChecks */
        $existsChecks = [];

        foreach ($rules as $field => $rule) {
            // ⚠️ `$form->validationRules()` 给出的值是**数组**：
            //      {"name": ["required"]}
            //    只处理字符串会让每一条规则都被跳过 —— 校验静默失效，
            //    必填字段留空照样能创建。实测踩到。
            $list = is_array($rule) ? $rule : [$rule];
            $parts = [];

            foreach ($list as $one) {
                if (! is_string($one) || $one === '') {
                    continue;
                }

                /*
                 * `exists:table,column` 要查库，TP 的 Validate 没有这条规则，
                 * 塞进规则串会被 translateRule 抛「尚未支持」。
                 * 所以在这里挑出来单独判（与 require 同一套做法）。
                 */
                if (str_starts_with($one, 'exists:')) {
                    $arg = substr($one, 7);
                    [$table, $column] = array_pad(explode(',', $arg, 2), 2, 'id');
                    $existsChecks[(string) $field] = [
                        'table' => trim($table),
                        'column' => trim($column) ?: 'id',
                    ];

                    continue; // 不进 TP 规则串
                }

                $parts[] = $one;
            }

            if ($parts === []) {
                continue;
            }

            $joined = implode('|', $parts);
            $translated[(string) $field] = $this->translateRule((string) $field, $joined);
            $messages[(string) $field.'.require'] = "{$field} 不能为空";
        }

        if ($translated === []) {
            return [];
        }

        /*
         * ⚠️ 必填**必须自己判**，不能交给 TP 的 Validate。
         *
         * 实测：`new Validate(['name' => 'require'])->check(['industry' => 'x'])`
         * 返回 **true** —— TP 的 require 对「字段完全缺失」不报错，
         * 而 Laravel 的 required 会。
         * 于是「表单写着必填、接口却照收空值」，正是幽灵能力的形态。
         */
        $errors = [];

        foreach ($translated as $field => $rule) {
            if (! str_contains($rule, 'require')) {
                continue;
            }

            $value = $data[$field] ?? null;

            if ($value === null || $value === '' || $value === []) {
                $errors[$field] = $messages[$field.'.require'] ?? "{$field} 不能为空";
            }
        }

        /*
         * `exists:` 也自己判 —— 见上面挑出它的原因。
         *
         * 语义与 Laravel 一致：值**为空就不查**（空值由 `nullable`/`require` 负责），
         * 非空则必须能在目标表命中。
         */
        foreach ($existsChecks as $field => $spec) {
            if (isset($errors[$field])) {
                continue; // 必填已经拦了，不用重复报
            }

            $value = $data[$field] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            try {
                $hit = Db::name($spec['table'])->where($spec['column'], $value)->count() > 0;
            } catch (\Throwable $e) {
                // 表/列不存在属于**声明错误**，要明确说出来，不能静默放行
                $errors[$field] = "字段 [{$field}] 的 exists 校验无法执行："
                    ."表 [{$spec['table']}] 或列 [{$spec['column']}] 不可用（{$e->getMessage()}）";

                continue;
            }

            if (! $hit) {
                $errors[$field] = $messages[$field.'.exists']
                    ?? "{$field} 选中的值不存在";
            }
        }

        /*
         * 其余规则交给 TP 的 Validate（max / min / email / in …）。
         *
         * ⚠️ 必须用 `(new Validate)->rule(...)->message(...)` 链式写法。
         *
         * think-validate v3 的构造函数**没有参数**：
         *     $ctor->getNumberOfParameters() === 0
         * 原先写的 `new Validate($translated, $messages)` 不报错，
         * 但规则集是**空的** —— `check()` 永远返回 true。
         * 于是除手写的 require 外，max/min/email/in/date/regex… **全部失效**
         * 且完全静默。这是本驱动最严重的一处幽灵能力。
         *
         * `batch(true)` 让 TP 一次报出**所有**失败字段（默认只报第一个），
         * 与 Laravel 的 `errors: {}` 形状对齐。
         */
        $validator = (new Validate)
            ->batch(true)
            ->rule($translated)
            ->message($messages);

        if (! $validator->check($data)) {
            /*
             * `getError(true)` 才是「字段 => 消息」的数组；
             * `getError()` 返回的是单个字符串，`+=` 到数组上会炸。
             */
            /** @var array<string, string> $extra */
            $extra = $validator->getError(true);

            $errors += $extra;
        }

        return $errors;
    }

    /**
     * Laravel 规则串 → ThinkPHP 规则串。
     *
     * 两端规则名不同（`required` vs `require`），且 TP 没有 Laravel 的
     * `unique:table,col` 等数据库规则。不认识的**直接抛** ——
     * 静默丢掉一条校验规则，比报错危险得多。
     *
     * 两个例外（都在本方法里显式处理，不是「悄悄跳过」）：
     *   · `nullable` —— TP 原生对 null 就跳过非 require 规则，语义已满足
     *   · `exists:` —— 在调用方挑出来单独查库（TP 没有这条规则）
     */
    protected function translateRule(string $field, string $rule): string
    {
        $map = [
            'required' => 'require',
            'integer' => 'integer',
            'numeric' => 'number',
            'string' => 'string',
            'boolean' => 'boolean',
            'email' => 'email',
            'url' => 'url',
            'date' => 'date',
            'array' => 'array',
            'max' => 'max',
            'min' => 'min',
            'in' => 'in',
            'regex' => 'regex',
        ];

        $out = [];

        foreach (explode('|', $rule) as $single) {
            $single = trim($single);

            if ($single === '') {
                continue;
            }

            $name = explode(':', $single, 2)[0];
            $args = explode(':', $single, 2)[1] ?? null;

            /*
             * `nullable` 是**修饰符**，不是一条校验规则。
             *
             * Laravel 语义：值为 null 时跳过该字段的其余规则。
             * TP 原生就是这个行为 —— 实测 `max:10` / `integer` / `email`
             * 对 null、空串、字段缺失**一律通过**，只有 `require` 会拦。
             *
             * 所以这里**丢掉它**不是「静默忽略」，而是「语义已由 TP 原生满足」。
             * 与下面 exists 的处理不同：那个是真需要额外实现。
             */
            if ($name === 'nullable') {
                continue;
            }

            if (! isset($map[$name])) {
                throw new \RuntimeException(
                    "字段 [{$field}] 的校验规则 [{$name}] 尚未在 ThinkPHP 驱动上支持。"
                    .'它不会被执行 —— 与其静默放过让声明变成幽灵能力，不如在这里明确报错。'
                );
            }

            $out[] = $map[$name].($args !== null ? ':'.$args : '');
        }

        return implode('|', $out);
    }

    /**
     * 把规则值归一化成「甲|乙」字符串。
     *
     * ⚠️ `$form->validationRules()` 给出的值**可能是数组**（如 `["required"]`），
     * 直接 `(string)` 会得到 "Array"。两处用到规则的地方都必须先过这里。
     */
    protected function normalizeRule(mixed $rule): string
    {
        if (is_string($rule)) {
            return $rule;
        }

        if (! is_array($rule)) {
            return '';
        }

        $parts = [];

        foreach ($rule as $one) {
            if (is_string($one) && $one !== '') {
                $parts[] = $one;
            }
        }

        return implode('|', $parts);
    }

    protected function dropRequired(string $rule): string
    {
        $parts = array_filter(
            array_map('trim', explode('|', $rule)),
            fn (string $r): bool => $r !== '' && ! str_starts_with($r, 'required')
        );

        return implode('|', $parts);
    }

    /**
     * 关联字段不是真实列，必须从主表数据里剔除。
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
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
                    static fn (mixed $v): bool => $v !== null && $v !== ''
                ));
            }

            // 关联字段不是真实列，必须从主表数据里剔除，否则 insert 会带上不存在的列
            unset($data[$field]);
        }

        return [$data, $relations];
    }

    /**
     * 同步多对多关联（写中间表）。
     *
     * ⚠️ 本次全面验收发现：TP 侧此前把 `splitRelationFields` 的第二个返回值
     * **丢掉了** —— 提交成功、不写中间表、不报错。
     * 这正是 Laravel 侧注释里记录过的那次「幽灵能力」事故的同形复现。
     */
    /**
     * @param  array<string, array<int, mixed>>  $relations
     */
    protected function syncRelations(object $model, array $relations): void
    {
        foreach ($relations as $relation => $ids) {
            if (method_exists($model, $relation)) {
                $model->{$relation}()->saveAll($ids);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    /**
     * `?sort=` 指向了不存在的列 → 422。
     *
     * 与 Laravel 侧 `ResourceController::sortColumnError()` 同一套语义：
     * 只拦「列根本不存在」；声明过但没标 sortable 的列照旧放行
     * （那种情况下 order 确实生效了，不算欺骗）。
     */
    protected function sortColumnError(ResourceNode $node, Request $request): ?Json
    {
        $sort = $this->scalar($request->param('sort'));

        if (! is_string($sort) || $sort === '') {
            return null;
        }

        $known = [];
        $sortable = [];

        foreach ($node->columns as $column) {
            $known[] = $column->name;

            if ($column->sortable) {
                $sortable[] = $column->name;
            }
        }

        // 列存在还不够 —— 必须**声明过** ->sortable()（宪法 ②「声明唯一」）
        if (in_array($sort, $sortable, true)) {
            return null;
        }

        return json((new SortColumnNotDeclaredException(
            uri: $node->uri,
            sort: $sort,
            sortable: $sortable,
            known: $known,
        ))->toArray(), 422);
    }

    /**
     * @return array<int, string>
     */
    protected function unknownQueryParams(Request $request): array
    {
        $supported = ['keyword', 'search', 'sort', 'direction', 'per_page', 'filters', 'page'];

        /** @var array<string, mixed> $query */
        $query = $request->get();

        return array_values(array_diff(array_keys($query), $supported));
    }

    protected function scalar(mixed $value): mixed
    {
        // 兼容 Stringable：只判 is_string 会让 MCP 传来的对象被静默忽略
        return $value instanceof \Stringable ? (string) $value : $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function filters(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $errors
     */
    protected function invalid(array $errors): Response
    {
        return Response::create([
            'error' => ['code' => 'VALIDATION_FAILED', 'message' => '提交的数据未通过校验。'],
            'errors' => $errors,
        ], 'json', 422);
    }

    /**
     * 唯一键 / NOT NULL 冲突 → 422 友好提示（而不是 500 HTML 错误页）。
     *
     * ⚠️ 本次全面验收发现：TP 侧此前没有这层转换，重复唯一值直接 500，
     * 调用方拿不到「是哪个字段重复」。
     */
    protected function constraintViolation(\Throwable $e, ResourceNode $node): Response
    {
        $message = $e->getMessage();

        // 唯一键：SQLite / MySQL 的措辞不同，都兜住
        if (preg_match('/UNIQUE constraint failed: ([\w.]+)/i', $message, $m)
            || preg_match("/Duplicate entry '.*' for key '([\w.]+)'/i", $message, $m)) {
            $field = str_contains($m[1], '.') ? explode('.', $m[1])[1] : $m[1];

            return Response::create([
                'error' => [
                    'code' => 'UNIQUE_CONSTRAINT_VIOLATION',
                    'message' => "字段「{$field}」的值已存在。",
                    'field' => $field,
                    'hint' => '该字段有唯一约束，请换一个值。',
                ],
            ], 'json', 422);
        }

        if (preg_match('/NOT NULL constraint failed: ([\w.]+)/i', $message, $m)
            || preg_match("/Column '([\w.]+)' cannot be null/i", $message, $m)) {
            $field = str_contains($m[1], '.') ? explode('.', $m[1])[1] : $m[1];

            return Response::create([
                'error' => [
                    'code' => 'REQUIRED_FIELD_MISSING',
                    'message' => "字段「{$field}」不能为空。",
                    'field' => $field,
                ],
            ], 'json', 422);
        }

        // 其它数据库错误照旧抛出 —— 不吞掉未知异常
        throw $e;
    }

    protected function notFound(string $uri, int|string $id): Response
    {
        return Response::create([
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => "Resource [{$uri}] 中不存在 id={$id} 的记录。",
            ],
        ], 'json', 404);
    }
}
