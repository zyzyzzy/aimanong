<?php

declare(strict_types=1);

namespace Aimanong\Schema;

use Aimanong\Support\Drivers;
use Aimanong\Contracts\Resource as ResourceContract;
use Aimanong\Contracts\SchemaIntrospector;
use Aimanong\Exceptions\GhostColumnException;
use Aimanong\Exceptions\InvalidQueryColumnException;
use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Schema\Ast\ColumnNode;
use Aimanong\Schema\Ast\FieldNode;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Schema\Introspector\IntrospectorResolver;
use Aimanong\Show\Show;
use Aimanong\Support\ColumnResolver;
use Aimanong\Tree\Tree;

/**
 * Schema 编译器。
 *
 * 把用户的 PHP Resource 声明编译为 ResourceNode（AST），
 * 再由各个 Emitter 产出不同格式的产物。
 *
 * 这是整个框架的枢纽：一份声明 → 四份产物，
 * 从根上消灭文档与实现漂移（AI 出错的最大来源）。
 *
 * **本类零 Illuminate 依赖** —— 凡需要问 ORM 要事实（表名 / 真实列清单 /
 * 某个方法是不是关联），一律走 `Contracts\SchemaIntrospector`。
 * 这样幽灵列检测在 Laravel 和 ThinkPHP 上是同一份实现，
 * 不会因为换 ORM 而静默失效。
 */
class Compiler
{
    protected SchemaIntrospector $introspector;

    /**
     * @param  SchemaIntrospector|null  $introspector  不传则按可用驱动自动挑选
     */
    public function __construct(?SchemaIntrospector $introspector = null)
    {
        $this->introspector = IntrospectorResolver::resolve($introspector);
    }

    /**
     * @param  class-string<ResourceContract>  $resource
     */
    public function compile(string $resource): ResourceNode
    {
        if (! class_exists($resource)) {
            throw new \InvalidArgumentException("Resource 类不存在: {$resource}");
        }

        $grid = new Grid;
        $form = new Form;
        $show = new Show;

        // 调用用户定义的 grid()/form()/show() 填充声明
        if (method_exists($resource, 'grid')) {
            $resource::grid($grid);
        }

        if (method_exists($resource, 'form')) {
            $resource::form($form);

            /*
             * 外键下拉：在这里才知道 Resource 的模型是什么，所以候选解析
             * 放在声明跑完之后（见 resolveRelations）。
             */
            $this->resolveRelations($form, $resource::model());
        }

        if (method_exists($resource, 'show')) {
            $resource::show($show);
        }

        $tree = null;
        if (method_exists($resource, 'tree')) {
            $candidate = new Tree;
            $resource::tree($candidate);

            // 仅当子类真的覆盖了 tree() 时才视为树形 Resource
            $ref = new \ReflectionMethod($resource, 'tree');
            if ($ref->getDeclaringClass()->getName() !== \Aimanong\Resource::class) {
                $tree = $candidate;
            }
        }

        $columns = $grid->toNodes();
        $fields = $form->toNodes();

        // 幽灵列/字段检测：拼错列名会静默消失，AI 拿不到任何反馈。
        // 这里在编译期显式抛出，错误信息里给出真实字段列表。
        $this->assertColumnsExist($resource, $columns, $fields);

        /*
         * 查询列校验：参与**搜索/排序/筛选**的列必须可安全进 SQL。
         *
         * 真实场景验证发现：关联列直接当列名用会 500，
         * 不存在的列会静默返回错误结果。这里在编译期就拒绝。
         */
        $this->assertQueryColumnsUsable($resource, $columns);

        return new ResourceNode(
            uri: $resource::uri(),
            label: $resource::label(),
            model: $resource::model(),
            columns: $columns,
            fields: $fields,
            detailFields: $show->toNodes(),
            meta: [
                'perPage' => $grid->toArray()['perPage'],
                'rules' => $form->validationRules(),
                // 导出配置随编译产物下发，控制器无需重复声明
                'exportable' => $grid->isExportable(),
                'exportColumns' => $grid->exportColumns(),
                'exportChunkSize' => $grid->getExportChunkSize(),
                /*
                 * 批量操作。
                 *
                 * ⚠️ 这里曾经**漏了**这一项 —— `Grid::toArray()` 产出了
                 * batchActions，但 Compiler 是逐项挑进 meta 的，
                 * 于是它连 schema 都到不了：
                 * 声明了、对外文档也写了，实际是个彻底的空操作。
                 */
                'batchActions' => $grid->getBatchActions(),
                // 自定义批量动作的完整声明（动作名 + 标签 + 处理器类名）
                'customBatchActions' => $grid->getCustomBatchActions(),
                'tree' => $tree?->toArray(),
                'stepped' => $form->isStepped(),
                // 只读 Resource：前端隐藏写按钮、后端拒绝写请求
                'readonly' => $this->isReadonly($resource),
                'steps' => $form->toArray()['steps'],
                // 声明了多对多关联的字段：写入时自动 sync，不写主表
                'relationFields' => $form->relationFields(),
                // 留空即不提交的字段（如编辑用户时的密码框）
                'omitWhenEmpty' => $form->omitWhenEmptyFields(),
                // 仅新增时必填（编辑留空表示不改，典型是密码框）
                'requiredOnCreate' => $form->requiredOnCreateFields(),
                /*
                 * Resource 类名本身。
                 *
                 * 控制器要拿它调用**声明方覆盖的授权钩子**
                 *（Resource::authorizeRelation）—— 没有这个字段就无从回调。
                 */
                'resource_class' => $resource,
            ],
        );
    }

    /**
     * 解析外键字段的候选（`$form->belongsTo(...)`）。
     *
     * ## 为什么放在编译期而不是声明期
     *
     * `$resource::form($form)` 是**静态**方法，拿不到模型类；
     * 而候选必须从关联模型查。编译期是唯一同时握有
     * 「字段声明」与「模型类」的地方。
     *
     * ## 解析不了就降级，不抛异常
     *
     * 「模型还没建」「表还没迁」「关联没定义」都是正常中间态 ——
     * 让整个 Resource 编译失败，比「这个字段暂时没有候选」更难排查。
     * 降级后由 ai:verify 给出可读的提示（见 Verifier）。
     */
    protected function resolveRelations(Form $form, string $model): void
    {
        $fields = $form->belongsToFields();

        if ($fields === [] || ! Drivers::hasRelationResolver()) {
            return;
        }

        $resolver = Drivers::relationResolver();

        if ($resolver === null) {
            return;
        }

        foreach ($fields as $field) {
            $resolved = $resolver->resolve($model, $field->resolvedRelationName(), $field->getLabelColumn());

            if ($resolved === null) {
                continue;
            }

            $field->applyResolved($resolved['options'], $resolved['exists']);
        }
    }

    /**
     * Resource 是否声明为只读。
     *
     * 用 method_exists 而不是直接调用：契约（Contracts\Resource）
     * 只约定 uri/label/model 三个必需方法，readonly() 属于**可选**声明，
     * 与 grid()/form()/show()/tree() 一致 —— 未声明即视为可写。
     *
     * @param  class-string<ResourceContract>  $resource
     */
    protected function isReadonly(string $resource): bool
    {
        if (! is_subclass_of($resource, \Aimanong\Resource::class)) {
            return false;
        }

        /** @var class-string<\Aimanong\Resource> $resource */
        return $resource::readonly();
    }

    /**
     * @param  array<int, class-string<ResourceContract>>  $resources
     * @return array<string, ResourceNode>
     */
    public function compileMany(array $resources): array
    {
        $nodes = [];

        foreach ($resources as $resource) {
            $nodes[$resource::uri()] = $this->compile($resource);
        }

        return $nodes;
    }

    /**
     * 校验参与查询的列（搜索/排序/筛选）可安全用于 SQL。
     *
     * @param  class-string  $resource
     * @param  array<int, ColumnNode>  $columns
     */
    protected function assertQueryColumnsUsable(string $resource, array $columns): void
    {
        try {
            $model = $resource::model();
        } catch (\Throwable) {
            return;
        }

        if (! class_exists($model)) {
            return;
        }

        // 只校验会进 SQL 的列
        $queryColumns = [];

        foreach ($columns as $c) {
            if ($c->searchable || $c->sortable || $c->filterable) {
                $queryColumns[] = $c->name;
            }
        }

        if ($queryColumns === []) {
            return;
        }

        $issues = ColumnResolver::validateMany($model, $queryColumns);

        if ($issues === []) {
            return;
        }

        throw new InvalidQueryColumnException(
            class_basename($resource),
            $issues
        );
    }

    /**
     * 校验列/字段在模型中真实存在。
     *
     * 背景：`$grid->column('typo_col')` 原先会静默通过，
     * 该列在结果里凭空消失，AI 拼错列名拿不到任何反馈。
     *
     * 兼容性：模型表不存在时（如单元测试 fixture）跳过检查，
     * 避免把环境问题误报成声明错误。
     *
     * @param  class-string  $resource
     * @param  array<int, ColumnNode>  $columns
     * @param  array<int, FieldNode>  $fields
     */
    protected function assertColumnsExist(string $resource, array $columns, array $fields): void
    {
        try {
            $model = $resource::model();
        } catch (\Throwable) {
            return; // model() 未实现，交由 Verifier 报告
        }

        if (! class_exists($model)) {
            return; // 模型缺失，交由 Verifier 报告
        }

        try {
            $instance = new $model;
            $table = $this->introspector->tableOf($instance);

            if ($table === null || ! $this->introspector->hasTable($table)) {
                return; // 表名不可判定 / 表不存在（如测试环境），跳过
            }

            $real = $this->introspector->columnsOf($table);
        } catch (\Throwable) {
            return; // 无数据库连接等情况，跳过检查
        }

        $ghosts = [];

        /*
         * 列：严格检查。列名拼错会导致该列在结果中凭空消失，
         * 这是 AI 拿不到任何反馈的主要场景。
         */
        foreach ($columns as $c) {
            if (in_array($c->name, $real, true) || $this->isVirtualAttribute($instance, $c->name)) {
                continue;
            }

            /*
             * 关联列（如 category.name）：点号前的部分是关联名。
             * 只要该关联在模型上存在，即视为合法 ——
             * 关联的**目标字段**归属另一张表，不在本表的列清单里。
             */
            if (str_contains($c->name, '.')) {
                $relation = explode('.', $c->name)[0];

                /*
                 * 不仅要求方法存在，还要要求它返回 Eloquent 关联 ——
                 * 否则 `foo.bar` 会因为模型上恰好有 foo() 方法而被放行，
                 * 运行时才发现不是关联。
                 */
                if (method_exists($instance, $relation)) {
                    try {
                        $result = $instance->{$relation}();

                        if ($this->introspector->isRelation($result)) {
                            continue;
                        }
                    } catch (\Throwable) {
                        // 关联定义有误 → 交给后续校验报告
                    }
                }
            }

            $ghosts[] = "column:{$c->name}";
        }

        /*
         * 表单字段：宽松检查。
         * 表单字段允许是虚拟字段（如 status、enabled 可能由
         * 访问器/修改器处理，或写入关联表），严格检查会误报。
         *
         * 仅在"疑似拼写错误"时才报告 —— 即与某个真实列名
         * 编辑距离很近但不相等。
         */
        foreach ($fields as $f) {
            if (in_array($f->name, $real, true) || $this->isVirtualAttribute($instance, $f->name)) {
                continue;
            }

            $typo = $this->closestColumn($f->name, $real);

            if ($typo !== null) {
                $ghosts[] = "field:{$f->name}";
            }
        }

        if ($ghosts === []) {
            return;
        }

        throw new GhostColumnException(
            sprintf(
                '%s 声明了不存在的列/字段: %s',
                class_basename($resource),
                implode(', ', $ghosts)
            ),
            $ghosts,
            $real
        );
    }

    /**
     * 是否为虚拟属性（访问器 / 修改器 / 已声明 casts / 同名关系）。
     *
     * 具体判定交给驱动（`Contracts\SchemaIntrospector`）——
     * Eloquent 的 `hasGetMutator` / `hasCast` 只在 Laravel 下存在，
     * 写死在这里会让 ThinkPHP 上把访问器误判成幽灵列。
     */
    protected function isVirtualAttribute(mixed $instance, string $name): bool
    {
        if (! is_object($instance)) {
            return false;
        }

        return $this->introspector->isVirtualAttribute($instance, $name);
    }

    /**
     * 找出与给定名称编辑距离很近的真实列名（疑似拼写错误）。
     *
     * @param  array<int, string>  $available
     */
    protected function closestColumn(string $name, array $available): ?string
    {
        $best = null;
        $shortest = -1;

        foreach ($available as $real) {
            $lev = levenshtein(strtolower($name), strtolower($real));

            if ($lev < $shortest || $shortest < 0) {
                $best = $real;
                $shortest = $lev;
            }
        }

        // 距离 <= 2 才算疑似拼写错误，避免把虚拟字段误判
        return ($shortest >= 0 && $shortest <= 2 && $shortest > 0) ? $best : null;
    }
}
