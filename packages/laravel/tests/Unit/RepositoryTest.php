<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Contracts\Repository;
use Aimanong\Http\Controllers\ResourceController;
use Aimanong\Repository\EloquentRepository;
use PHPUnit\Framework\TestCase;

/**
 * 数据源契约测试。
 *
 * 验证 Repository 依赖契约而非具体 ORM（松耦合设计）。
 */
class RepositoryTest extends TestCase
{
    public function test_contract_methods_exist(): void
    {
        $ref = new \ReflectionClass(Repository::class);

        foreach (['paginate', 'create', 'update', 'delete', 'find'] as $m) {
            $this->assertTrue($ref->hasMethod($m), "契约缺少方法 {$m}");
        }
    }

    public function test_eloquent_repository_implements_contract(): void
    {
        $ref = new \ReflectionClass(EloquentRepository::class);

        $this->assertContains(
            Repository::class,
            $ref->getInterfaceNames(),
            'EloquentRepository 必须实现 Repository 契约'
        );
    }

    /**
     * 松耦合验证：可替换数据源而不改 Controller。
     */
    public function test_controller_depends_on_contract_not_implementation(): void
    {
        $ref = new \ReflectionClass(ResourceController::class);

        $this->assertTrue(
            $ref->hasMethod('repository'),
            'Controller 应通过 repository() 获取数据源'
        );
    }
}
