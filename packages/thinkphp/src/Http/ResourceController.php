<?php

declare(strict_types=1);

namespace Aimanong\ThinkPHP\Http;

use Aimanong\Aimanong;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Support\Drivers;
use Aimanong\ThinkPHP\ThinkDataSource;
use think\db\exception\ModelNotFoundException;
use think\Request;
use think\Response;
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
    public const IMPLEMENTED = ['index', 'show', 'store', 'update', 'destroy'];

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
        $declared = is_array($node->meta) ? ($node->meta['perPage'] ?? 20) : 20;

        if (! is_numeric($perPage)) {
            $perPage = $declared;
        }

        $relations = [];

        foreach ($node->columns as $column) {
            if (str_contains($column->name, '.')) {
                $relations[] = $column->name;
            }
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
        if (($deny = $this->deny($uri, 'store')) !== null) {
            return $deny;
        }

        
        $node = $this->resolve($uri);

        $errors = $this->validateInput($request, $node, true);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $data = $this->payload($request);
        [$data] = $this->splitRelationFields($node, $data);

        $model = $this->repository($node)->create($data);

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
        [$data] = $this->splitRelationFields($node, $data);

        try {
            $model = $this->repository($node)->update($id, $data);
        } catch (ModelNotFoundException) {
            return $this->notFound($uri, $id);
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
            throw new \think\exception\HttpException(404, "Resource [{$uri}] 未注册");
        }

        return $this->node = (new Compiler)->compile($class);
    }

    protected function repository(ResourceNode $node): ThinkDataSource
    {
        /** @var class-string<\think\Model> $model */
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
        $meta = is_array($node->meta) ? $node->meta : [];
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
                    $rules[$name] = $this->dropRequired((string) ($rules[$name] ?? ''));
                }
            }
        }

        $translated = [];
        $messages = [];

        foreach ($rules as $field => $rule) {
            // ⚠️ `$form->validationRules()` 给出的值是**数组**：
            //      {"name": ["required"]}
            //    只处理字符串会让每一条规则都被跳过 —— 校验静默失效，
            //    必填字段留空照样能创建。实测踩到。
            $list = is_array($rule) ? $rule : [$rule];
            $parts = [];

            foreach ($list as $one) {
                if (is_string($one) && $one !== '') {
                    $parts[] = $one;
                }
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

        // 其余规则交给 TP 的 Validate（max / min / email / in …）
        $validator = new Validate($translated, $messages);

        if (! $validator->check($data)) {
            /** @var array<string, string> $extra */
            $extra = $validator->getError();

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
        $relations = [];

        foreach (array_keys($data) as $key) {
            if (str_contains((string) $key, '.') || str_contains((string) $key, '[')) {
                $relations[(string) $key] = $data[$key];
                unset($data[$key]);
            }
        }

        return [$data, $relations];
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
