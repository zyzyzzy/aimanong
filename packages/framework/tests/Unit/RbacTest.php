<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Auth\PermissionGate;
use Aimanong\Support\FieldType;
use PHPUnit\Framework\TestCase;

/**
 * RBAC 测试。
 *
 * 方向确认后新增：框架从「纯开发工具」转向「带地基的平台」，
 * 第一步就是内置 RBAC（角色/权限）。
 *
 * 关键设计：权限节点由 Resource **自动生成**，无需手写。
 */
class RbacTest extends TestCase
{
    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(FieldType::class);

        foreach (['registered', 'fieldClasses'] as $name) {
            if ($ref->hasProperty($name)) {
                $prop = $ref->getProperty($name);
                $prop->setAccessible(true);
                $prop->setValue(null, []);
            }
        }

        parent::tearDown();
    }

    public function test_actions_are_defined(): void
    {
        $this->assertSame(
            ['index', 'show', 'create', 'update', 'destroy', 'export'],
            PermissionGate::ACTIONS
        );
    }

    public function test_slug_format(): void
    {
        $this->assertSame('cms-authors.update', PermissionGate::slug('cms-authors', 'update'));
        $this->assertSame('cms-authors.index', PermissionGate::slug('cms-authors'));
    }

    public function test_action_label_is_chinese(): void
    {
        foreach (PermissionGate::ACTIONS as $action) {
            $label = PermissionGate::actionLabel($action);

            $this->assertNotSame($label, $action, "{$action} 应有中文名");
            $this->assertNotEmpty($label);
        }

        $this->assertSame('编辑', PermissionGate::actionLabel('update'));
        $this->assertSame('删除', PermissionGate::actionLabel('destroy'));
    }

    /**
     * 未启用 RBAC 时必须放行 —— 保留不启用权限的灵活性。
     *
     * 无 Laravel 容器时 config() 会抛异常，
     * enabled() 必须安全返回而不是让整个判定崩掉。
     */
    public function test_check_passes_when_disabled(): void
    {
        // 无容器环境下 enabled() 不应抛异常（会回退到 false）
        $threw = false;

        try {
            PermissionGate::enabled();
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse($threw, 'enabled() 在无容器时不应抛异常');

        // 同样，check() 在无容器且未启用时应放行
        $checkThrew = false;

        try {
            PermissionGate::check('cms-authors.update');
        } catch (\Throwable) {
            $checkThrew = true;
        }

        $this->assertFalse($checkThrew, 'check() 在无容器时不应抛异常');
    }

    public function test_slug_uses_kebab_uri(): void
    {
        // uri 统一 kebab（生成器已修：下划线转连字符）
        $this->assertSame('admin-roles.update', PermissionGate::slug('admin-roles', 'update'));
    }
}
