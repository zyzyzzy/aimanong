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

        return new ResourceNode(
            uri: $resource::uri(),
            label: $resource::label(),
            model: $resource::model(),
            columns: $grid->toNodes(),
            fields: $form->toNodes(),
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
}
