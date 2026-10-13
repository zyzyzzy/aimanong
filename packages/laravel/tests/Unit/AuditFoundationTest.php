<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Capabilities;
use Aimanong\Auth\PermissionGate;
use Aimanong\Foundation\Audit\AuditRecorder;
use Aimanong\Foundation\Audit\OperationLog;
use Aimanong\Mcp\Tools\SearchDocs;
use Aimanong\Schema\Compiler;
use Aimanong\Schema\Emitters\JsonSchemaEmitter;
use Laravel\Mcp\Request;
use PHPUnit\Framework\TestCase;

/**
 * 基座能力（P0）测试。
 *
 * 覆盖两件事：
 *   1. 「只读资源」这条声明式能力在三层都生效
 *      （编译产物 / 权限节点 / 前端契约）
 *   2. 审计日志的机密字段不落库
 */
class AuditFoundationTest extends TestCase
{
    public function test_readonly_flag_reaches_compiled_schema(): void
    {
        /** @var class-string<\Aimanong\Contracts\Resource> $class */
        $class = Fixtures\ReadonlyResource::class;

        $json = (new JsonSchemaEmitter)->emit((new Compiler)->compile($class));

        $this->assertTrue(
            $json['readonly'],
            'readonly() 必须进入 schema.json —— 前端靠它隐藏写入口'
        );
    }

    public function test_writable_resource_is_not_readonly(): void
    {
        /** @var class-string<\Aimanong\Contracts\Resource> $class */
        $class = Fixtures\ArticleResource::class;

        $json = (new JsonSchemaEmitter)->emit((new Compiler)->compile($class));

        $this->assertFalse($json['readonly']);
    }

    public function test_readonly_resource_has_no_write_permissions(): void
    {
        /** @var class-string<\Aimanong\Contracts\Resource> $class */
        $class = Fixtures\ReadonlyResource::class;

        $actions = PermissionGate::actionsFor((new Compiler)->compile($class));

        $this->assertSame(['index', 'show', 'export'], $actions);
        $this->assertNotContains('create', $actions);
        $this->assertNotContains('update', $actions);
        $this->assertNotContains('destroy', $actions);
    }

    public function test_writable_resource_keeps_all_permissions(): void
    {
        /** @var class-string<\Aimanong\Contracts\Resource> $class */
        $class = Fixtures\ArticleResource::class;

        $actions = PermissionGate::actionsFor((new Compiler)->compile($class));

        $this->assertSame(PermissionGate::ACTIONS, $actions);
    }

    /**
     * 单一来源：Capabilities 里有的基座能力，search-docs 必须也有。
     *
     * 这是本项目被 AI 实测打脸过三次的坑，新增能力必须同时接三处。
     */
    public function test_search_docs_includes_foundation_capabilities(): void
    {
        $text = (new SearchDocs)->handle(new Request([]))->content()->toArray()['text'] ?? '';

        foreach (array_keys((new Capabilities)->foundationCapabilities()) as $key) {
            $this->assertStringContainsString(
                $key,
                $text,
                "search-docs 缺少基座能力 {$key} —— 与 capabilities.json 数据分叉"
            );
        }

        // 声明写法必须能被 AI 直接抄走
        $this->assertStringContainsString('readonly()', $text);
    }

    public function test_capabilities_expose_audit_log_uris(): void
    {
        $foundation = (new Capabilities)->foundationCapabilities();

        $this->assertSame('admin-operation-logs', $foundation['operation_log']['uri']);
        $this->assertSame('admin-login-logs', $foundation['login_log']['uri']);
    }

    /**
     * 密码、token 一律掩码 —— 审计日志里出现明文密码是不可接受的。
     */
    public function test_sensitive_fields_are_masked(): void
    {
        $masked = AuditRecorder::mask([
            'username' => 'admin',
            'password' => 'p@ssw0rd',
            'password_confirmation' => 'p@ssw0rd',
            'new_password' => 'another',
            'api_key' => 'sk-xxx',
            'remember_token' => 'abc',
            'profile' => [
                'secret' => 'shh',
                'nickname' => '张三',
            ],
            'avatar' => 'a.png',
        ]);

        $this->assertSame('admin', $masked['username']);
        $this->assertSame('******', $masked['password']);
        $this->assertSame('******', $masked['password_confirmation']);
        $this->assertSame('******', $masked['new_password']);
        $this->assertSame('******', $masked['api_key']);
        $this->assertSame('******', $masked['remember_token']);
        $this->assertSame('******', $masked['profile']['secret']);
        $this->assertSame('张三', $masked['profile']['nickname']);
        $this->assertSame('a.png', $masked['avatar']);
    }

    /**
     * 动作词表必须覆盖 AuditRecorder 能产出的全部动作。
     *
     * 漏一个，列表里就会出现没有文字的空徽章（实测踩过：
     * 登录请求的 action 为 null，徽章渲染成一个灰点）。
     */
    public function test_action_vocabulary_covers_every_action(): void
    {
        $options = OperationLog::actionOptions();

        $emitted = ['store', 'update', 'destroy', 'export', 'move', 'index', 'show', 'login', 'logout'];

        foreach ($emitted as $action) {
            $this->assertArrayHasKey($action, $options, "动作词表缺少 {$action}");
            $this->assertNotSame('', $options[$action]);
        }
    }
}
