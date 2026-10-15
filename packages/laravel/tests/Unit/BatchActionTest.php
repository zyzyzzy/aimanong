<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Contracts\BatchActionHandler;
use Aimanong\Grid\Grid;
use Aimanong\Schema\Ast\ResourceNode;
use Aimanong\Support\PermissionNodes;
use PHPUnit\Framework\TestCase;

/**
 * 声明式自定义批量动作。
 *
 * ## 之前是什么样
 *
 * `batchActions()` 是硬白名单 {delete, export}，声明层不收闭包（ADR-003）。
 * 实测中一个 LLM 为做「批量改状态」，把 23 个可能的动作名逐个塞进去
 * 全部被拒，最后自己写扩展 + 注入 JS + 自建路由 —— 而它自己在报告里
 * 标出这条路丢了**权限校验**与**字段校验**。
 *
 * ## 现在是什么样
 *
 * ADR-003 说的是「声明层只放数据」，不是「不能有自定义动作」。
 * 中间那条路是**引用类名**：数据留在声明里，行为留在代码里。
 */
class BatchActionTest extends TestCase
{
    public function test_custom_action_appears_in_batch_actions(): void
    {
        $grid = new Grid;
        $grid->batchAction('ship', '标记为已发货')->handler(StubShipHandler::class);
        $grid->batchActions(['delete']);

        $this->assertSame(['delete', 'ship'], $grid->getBatchActions());
    }

    public function test_custom_action_carries_handler_class(): void
    {
        $grid = new Grid;
        $grid->batchAction('ship', '标记为已发货')->handler(StubShipHandler::class);

        $declared = $grid->getCustomBatchActions();

        $this->assertCount(1, $declared);
        $this->assertSame('ship', $declared[0]['name']);
        $this->assertSame('标记为已发货', $declared[0]['label']);
        $this->assertSame(StubShipHandler::class, $declared[0]['handler']);
    }

    public function test_action_without_handler_fails_at_compile_time(): void
    {
        /*
         * 声明了动作却没给处理器 —— 必须**编译期**就报错。
         * 否则运行期表现为「点按钮 501」，正是本项目最反对的
         * 「声明了却不能用」。
         */
        $grid = new Grid;
        $grid->batchAction('ship', '标记为已发货');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/没有指定处理器/');

        $grid->getCustomBatchActions();
    }

    public function test_handler_must_implement_the_contract(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/没有实现/');

        (new Grid)->batchAction('ship', '发货')->handler(\stdClass::class);
    }

    public function test_handler_class_must_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/不存在/');

        (new Grid)->batchAction('ship', '发货')->handler('App\\Nope\\Missing');
    }

    public function test_cannot_redefine_builtin_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/内置动作/');

        (new Grid)->batchAction('delete', '删掉');
    }

    public function test_custom_action_becomes_a_permission_node(): void
    {
        /*
         * 自定义动作也必须是权限节点。
         *
         * 不做这一步的后果很具体：动作能调，但**没有任何节点管得住它** ——
         * 实测里那个自建批量接口就是因为这个丢了权限校验。
         */
        $node = new ResourceNode(
            uri: 'orders',
            label: '订单',
            model: \stdClass::class,
            columns: [],
            fields: [],
            detailFields: [],
            meta: [
                'customBatchActions' => [
                    ['name' => 'ship', 'label' => '标记为已发货', 'handler' => StubShipHandler::class],
                ],
            ],
        );

        $this->assertContains('ship', PermissionNodes::actionsFor($node));
        $this->assertSame('标记为已发货', PermissionNodes::labelFor($node, 'ship'));
    }

    public function test_builtin_actions_still_pass_through_the_whitelist(): void
    {
        $grid = new Grid;
        $grid->batchActions(['delete', 'export']);

        $this->assertSame(['delete', 'export'], $grid->getBatchActions());
        $this->assertSame([], $grid->getCustomBatchActions());
    }

    public function test_unknown_builtin_action_still_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Grid)->batchActions(['ship']);
    }
}

class StubShipHandler implements BatchActionHandler
{
    public function handle(string $model, array $ids): int
    {
        return count($ids);
    }
}
