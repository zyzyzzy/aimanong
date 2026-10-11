<?php

declare(strict_types=1);

namespace Aimanong\Schema;

use Aimanong\Contracts\Resource as ResourceContract;
use Aimanong\Exceptions\GhostColumnException;
use Aimanong\Exceptions\InvalidQueryColumnException;
use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Resource;
use Aimanong\Schema\Ast\ColumnNode;
use Aimanong\Schema\Ast\FieldNode;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Show\Show;
use Aimanong\Support\ColumnResolver;
use Aimanong\Tree\Tree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

/**
 * Schema 编译器。
 *
 * 把用户的 PHP Resource 声明编译为 ResourceNode（AST），
 * 再由各个 Emitter 产出不同格式的产物。
 *
 * 这是整个框架的枢纽：一份声明 → 四份产物，
 * 从根上消灭文档与实现漂移（AI 出错的最大来源）。
 */
class Compiler
{
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
            ],
        );
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
            /** @var Model $instance */
            $instance = new $model;
            $table = $instance->getTable();

            if (! Schema::hasTable($table)) {
                return; // 表不存在（如测试环境），跳过
            }

            $real = Schema::getColumnListing($table);
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

                        if ($result instanceof Relation) {
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
     * 是否为虚拟属性（访问器 / 修改器 / 已声明 casts）。
     *
     * 注意：不能依赖 hasAttribute() —— 新建模型实例的属性
     * 数组为空，对任何字段都返回 false。
     */
    protected function isVirtualAttribute(mixed $instance, string $name): bool
    {
        if (! $instance instanceof Model) {
            return false;
        }

        /*
         * 访问器：两种写法都要认。
         *
         * Laravel 9+ 推荐 `protected function xxx(): Attribute`，
         * 对应的检测 API 是 hasAttributeGetMutator() ——
         * 只检查传统写法 hasGetMutator() 会把现代写法误判成「幽灵列」
         * （CMS 场景验证发现）。
         */
        if ($instance->hasGetMutator($name)) {
            return true;
        }

        if ($instance->hasAttributeGetMutator($name)) {
            return true;
        }

        // 已声明 casts
        if ($instance->hasCast($name)) {
            return true;
        }

        // 已定义的同名关系
        if (method_exists($instance, $name)) {
            try {
                $ref = new \ReflectionMethod($instance, $name);

                return $ref->getNumberOfRequiredParameters() === 0;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
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
