<?php

declare(strict_types=1);

namespace Aimanong\Schema;

use Aimanong\Contracts\Resource as ResourceContract;
use Aimanong\Form\Form;
use Aimanong\Grid\Grid;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Show\Show;

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

        $grid = new Grid();
        $form = new Form();
        $show = new Show();

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

        $columns = $grid->toNodes();
        $fields = $form->toNodes();

        // 幽灵列/字段检测：拼错列名会静默消失，AI 拿不到任何反馈。
        // 这里在编译期显式抛出，错误信息里给出真实字段列表。
        $this->assertColumnsExist($resource, $columns, $fields);

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
            ],
        );
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
     * 校验列/字段在模型中真实存在。
     *
     * 背景：`$grid->column('typo_col')` 原先会静默通过，
     * 该列在结果里凭空消失，AI 拼错列名拿不到任何反馈。
     *
     * 兼容性：模型表不存在时（如单元测试 fixture）跳过检查，
     * 避免把环境问题误报成声明错误。
     *
     * @param  class-string  $resource
     * @param  array<int, \Aimanong\Schema\Ast\ColumnNode>  $columns
     * @param  array<int, \Aimanong\Schema\Ast\FieldNode>  $fields
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
            /** @var \Illuminate\Database\Eloquent\Model $instance */
            $instance = new $model();
            $table = $instance->getTable();

            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                return; // 表不存在（如测试环境），跳过
            }

            $real = \Illuminate\Support\Facades\Schema::getColumnListing($table);
        } catch (\Throwable) {
            return; // 无数据库连接等情况，跳过检查
        }

        $ghosts = [];

        foreach ($columns as $c) {
            // 允许访问器等非物理列：仅当模型声明了该属性时才跳过
            if (in_array($c->name, $real, true) || $instance->hasAttribute($c->name)
                || method_exists($instance, 'getAttribute') && $instance->hasGetMutator($c->name)) {
                continue;
            }
            $ghosts[] = "column:{$c->name}";
        }

        foreach ($fields as $f) {
            if (in_array($f->name, $real, true) || $instance->hasAttribute($f->name)) {
                continue;
            }
            $ghosts[] = "field:{$f->name}";
        }

        if ($ghosts === []) {
            return;
        }

        throw new \Aimanong\Exceptions\GhostColumnException(
            sprintf(
                '%s 声明了不存在的列/字段: %s',
                class_basename($resource),
                implode(', ', $ghosts)
            ),
            $ghosts,
            $real
        );
    }
}
