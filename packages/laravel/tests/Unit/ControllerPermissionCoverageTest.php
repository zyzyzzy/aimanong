<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Http\Controllers\ResourceController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 结构性守卫：控制器的每个公开动作都必须做权限校验。
 *
 * ## 为什么写这个
 *
 * 实测踩到过一次**真实越权**：`tree()` 与 `move()` 忘了调 `authorize_()`，
 * 而其余方法都调了。后果（已用零权限账号实测）：
 *
 *     GET  /admin/api/departments        → 403  ✅
 *     GET  /admin/api/departments/tree   → 200  ❌ 拿到整张表
 *     PUT  /admin/api/departments/4/move → 200  ❌ parent_id 真被改了
 *
 * `authorize_()` 一次调用同时负责三件事：跨后台归属（404）、只读资源、
 * 以及 RBAC —— 漏掉它的方法三样全丢。
 *
 * 逐个方法 review 会再漏（就是这么漏的）。所以这里改成**枚举**：
 * 新增一个动作方法如果忘了鉴权，这个测试立刻红。
 */
class ControllerPermissionCoverageTest extends TestCase
{
    /**
     * 允许不做权限校验的公开方法。
     *
     * 每加一个都必须写明理由 —— 否则这个白名单会变成「漏了就加进去」的垃圾桶。
     *
     * @var array<string, string>
     */
    private const EXEMPT = [
        // 未实现的占位动作：直接回 501，不读也不写数据。
        'unimplemented' => '只返回 501，不接触任何数据',
    ];

    public function test_every_public_action_checks_permission(): void
    {
        $ref = new ReflectionClass(ResourceController::class);
        $missing = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $name = $method->getName();

            if (isset(self::EXEMPT[$name])) {
                continue;
            }

            // 只检查「动作」方法：带 $uri 参数的才是路由入口
            $params = array_map(
                static fn ($p) => $p->getName(),
                $method->getParameters()
            );

            if (! in_array('uri', $params, true)) {
                continue;
            }

            if (! $this->callsAuthorize($method)) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            '这些动作没有调用 authorize_()（越权风险）: '.implode(', ', $missing).
            "\n每个动作都要过 authorize_\$uri, \$action) —— 它同时管归属 404、只读、RBAC。"
        );
    }

    private function callsAuthorize(ReflectionMethod $method): bool
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);

        return str_contains(implode('', $lines), 'authorize_(');
    }
}
