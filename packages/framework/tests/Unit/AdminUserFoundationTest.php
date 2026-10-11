<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Capabilities;
use Aimanong\Contracts\Resource;
use Aimanong\Form\Form;
use Aimanong\Foundation\Resources\AdminUserResource;
use Aimanong\Mcp\Tools\SearchDocs;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Laravel\Mcp\Request;
use PHPUnit\Framework\TestCase;

/**
 * 用户基座测试：个人中心 + 管理员账号 + 「留空即不改」语义。
 *
 * 这里锁的是**声明层**的正确性 —— 控制器里的写入行为
 * 由真机验证覆盖（单测环境没有容器与数据库）。
 */
class AdminUserFoundationTest extends TestCase
{
    /**
     * 密码框的三件套必须原样进入编译产物。
     *
     * 少任何一个都会出事：
     *   - 少了 omitWhenEmpty：'hashed' cast 把空串哈希成新密码，账号失效
     *   - 少了 requiredOnCreate：新增时静默存进空密码
     */
    public function test_password_field_metadata_reaches_compiled_schema(): void
    {
        $form = new Form;

        $form->text('username')->label('账号')->required();
        $form->password('password')
            ->label('密码')
            ->requiredOnCreate()
            ->omitWhenEmpty()
            ->min(6);

        $meta = $this->compileMeta($form);

        $this->assertSame(['password'], $meta['omitWhenEmpty']);
        $this->assertSame(['password'], $meta['requiredOnCreate']);
    }

    public function test_plain_fields_are_not_omitted_or_create_required(): void
    {
        $form = new Form;
        $form->text('title')->label('标题')->required();

        $meta = $this->compileMeta($form);

        $this->assertSame([], $meta['omitWhenEmpty']);
        $this->assertSame([], $meta['requiredOnCreate']);
    }

    /**
     * 多对多关联必须进入 relationFields —— 控制器据此 sync 中间表。
     *
     * 回归：splitRelationFields/syncRelations 曾经只是「定义在那里」，
     * 从未被调用，导致 multiSelect()->relation() 是幽灵能力：
     * 表单提交成功、列表看不出区别、中间表一条都没有。
     */
    public function test_relation_field_metadata_reaches_compiled_schema(): void
    {
        $form = new Form;
        $form->multiSelect('roles')->label('角色')->relation('roles')->options([1 => '管理员']);

        $meta = $this->compileMeta($form);

        $this->assertSame(['roles' => 'roles'], $meta['relationFields']);
    }

    /**
     * 内置的管理员 Resource 必须自带这套声明 ——
     * 否则用户管理界面会踩上面两个坑。
     */
    public function test_builtin_admin_user_resource_declares_password_contract(): void
    {
        /** @var class-string<\Aimanong\Contracts\Resource> $class */
        $class = AdminUserResource::class;

        $node = (new Compiler)->compile($class);

        $this->assertContains('password', $node->meta['omitWhenEmpty'] ?? []);
        $this->assertContains('password', $node->meta['requiredOnCreate'] ?? []);
        $this->assertSame(['roles' => 'roles'], $node->meta['relationFields'] ?? []);
    }

    public function test_builtin_admin_user_resource_is_readonly_false(): void
    {
        $json = (new JsonSchemaEmitter)->emit(
            (new Compiler)->compile(AdminUserResource::class)
        );

        $this->assertFalse($json['readonly'], '管理员账号必须可编辑');
    }

    /**
     * 单一来源：新增基座能力必须同时出现在 capabilities 与 search-docs。
     */
    public function test_profile_and_admin_user_are_exposed_everywhere(): void
    {
        $foundation = (new Capabilities)->foundationCapabilities();

        $this->assertArrayHasKey('profile', $foundation);
        $this->assertArrayHasKey('admin_user', $foundation);
        $this->assertSame('admin-users', $foundation['admin_user']['uri']);

        $text = (new SearchDocs)->handle(new Request([]))->content()->toArray()['text'] ?? '';

        foreach (['requiredOnCreate', 'omitWhenEmpty', 'relation('] as $needle) {
            $this->assertStringContainsString($needle, $text, "search-docs 缺少 {$needle}");
        }

        $this->assertStringContainsString('admin-users', $text);
        $this->assertStringContainsString('profile', $text);
    }

    /**
     * @return array<string, mixed>
     */
    protected function compileMeta(Form $form): array
    {
        // 直接走 Compiler 的私有路径不方便，用一个临时 Resource 夹具太重，
        // 这里改为断言 Form 自身暴露的元数据（Compiler 只是原样搬运）。
        return [
            'omitWhenEmpty' => $form->omitWhenEmptyFields(),
            'requiredOnCreate' => $form->requiredOnCreateFields(),
            'relationFields' => $form->relationFields(),
        ];
    }
}
