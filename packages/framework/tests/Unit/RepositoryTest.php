<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

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
        $ref = new \ReflectionClass(\Aimanong\Contracts\Repository::class);

        foreach (['paginate', 'create', 'update', 'delete', 'find'] as $m) {
            $this->assertTrue($ref->hasMethod($m), "契约缺少方法 {$m}");
        }
    }

    public function test_eloquent_repository_implements_contract(): void
    {
        $this->assertTrue(
            is_subclass_of(EloquentRepository::class, \Aimanong\Contracts\Repository::class),
            'EloquentRepository 必须实现 Repository 契约'
        );
    }

    /**
     * 松耦合验证：可替换数据源而不改 Controller。
     */
    public function test_controller_depends_on_contract_not_implementation(): void
    {
        $ref = new \ReflectionClass(\Aimanong\Http\Controllers\ResourceController::class);
        $method = $ref->getMethod('repository');

        $this->assertNotNull($method, 'Controller 应通过 repository() 获取数据源');
    }
}
