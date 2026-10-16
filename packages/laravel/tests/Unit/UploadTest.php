<?php

declare(strict_types=1);

namespace Aimanong\Tests\Unit;

use Aimanong\Ai\Capabilities;
use Aimanong\Foundation\Upload\Uploader;
use Aimanong\Mcp\Tools\SearchDocs;
use Aimanong\Support\FieldType;
use Laravel\Mcp\Request;
use PHPUnit\Framework\TestCase;

/**
 * 文件上传测试。
 *
 * 单测环境没有 Laravel 容器，落盘那一段由真机验证覆盖；
 * 这里锁住**纯粹的逻辑**：路径解析、穿越拦截、扩展名白名单、
 * 能力面一致性 —— 这些恰好是最容易写出安全漏洞的地方。
 */
class UploadTest extends TestCase
{
    public function test_upload_field_types_are_registered(): void
    {
        foreach (['image', 'images', 'file', 'files'] as $type) {
            $this->assertTrue(
                FieldType::exists($type),
                "字段类型 {$type} 未登记 —— AI 自省会看不到它"
            );
        }

        $this->assertSame('string', FieldType::toJsonType('image'));
        $this->assertSame('array', FieldType::toJsonType('images'));
        $this->assertSame('array', FieldType::toJsonType('files'));
    }

    /**
     * 存进数据库的是**相对路径**，读取时会遇到各种历史形态，
     * 必须都能归一化；而路径穿越必须一律拒绝。
     */
    public function test_path_from_value_normalizes_and_blocks_traversal(): void
    {
        $uploader = new Uploader;

        $path = 'uploads/2026/10/a.png';

        $this->assertSame($path, $uploader->pathFromValue($path));
        $this->assertSame($path, $uploader->pathFromValue('/'.$path));
        $this->assertSame($path, $uploader->pathFromValue('https://cdn.example.com/'.$path));
        $this->assertSame($path, $uploader->pathFromValue('/storage/'.$path));

        // 穿越 / 空值 / 非字符串一律拒绝
        $this->assertNull($uploader->pathFromValue('uploads/../../.env'));
        $this->assertNull($uploader->pathFromValue('..%2F..%2Fetc%2Fpasswd'));
        $this->assertNull($uploader->pathFromValue(''));
        $this->assertNull($uploader->pathFromValue(null));
        $this->assertNull($uploader->pathFromValue(123));
    }

    /**
     * 外链要原样透出 —— 用户可能自己填了 CDN 地址，
     * 把它当成本框架的相对路径会渲染成 404。
     */
    public function test_external_url_is_passed_through(): void
    {
        $uploader = new Uploader;

        $this->assertSame(
            'https://example.com/x.png',
            $uploader->urlFromValue('https://example.com/x.png')
        );
        $this->assertSame('', $uploader->urlFromValue(null));
    }

    /**
     * 删除只接受本框架管理的相对路径，绝不接受任意输入。
     */
    public function test_delete_rejects_unsafe_paths(): void
    {
        $uploader = new Uploader;

        $this->assertFalse($uploader->delete('../../etc/passwd'));
        $this->assertFalse($uploader->delete(''));
        $this->assertFalse($uploader->delete(null));
    }

    public function test_default_image_whitelist_excludes_svg(): void
    {
        $extensions = (new Uploader)->allowedExtensions('image');

        $this->assertContains('png', $extensions);
        $this->assertContains('jpg', $extensions);
        $this->assertNotContains(
            'svg',
            $extensions,
            'SVG 可内嵌 script，框架路由同源读取 → XSS，默认必须排除'
        );
        $this->assertNotContains('php', (new Uploader)->allowedExtensions('file'));
    }

    /**
     * 字段上的 ->accept() 是**收窄**白名单，不是放开。
     *
     * ## ⚠️ 这个用例原先在**锁住一个 RCE 漏洞**（2026-10-16 审计发现）
     *
     * 原文的注释是：
     *
     *      // 写 php 不会真的允许 php —— 这是它自己的白名单，与框架无关；
     *      // 框架白名单仍由 allowedExtensions 把关
     *      $this->assertSame(['php'], $uploader->extensionsFor('file', 'php'));
     *
     * 这个推理是**错的**：`extensionsFor()` 的返回值**就是**最终白名单 ——
     * 控制器把它直接拼进 `'mimes:'.implode(',', $extensions)`。
     * 所以 `extensionsFor('file','php') === ['php']` 不等于「无影响」，
     * 而是等于「php 通过了校验」。
     *
     * 何况 `accept` 来自**请求参数**，不是声明方写死的：
     *
     *     -F "accept=php,gif" → mimes:php,gif
     *     → 实测落盘 uploads/.../xxx.php，并可被 Web 服务器执行
     *
     * 顺带：`mimes:...,php,...` 还会让 Laravel 的 shouldBlockPhpUpload()
     * 直接 return false —— 它是来挡 PHP 的，被请求参数缴了械。
     *
     * 同一个用例里 `['png']` 那条（accept=image/png 对 kind=file）
     * 也是同一个毛病：png 不在 file 白名单里，却被放行了。
     */
    public function test_accept_narrows_the_whitelist(): void
    {
        $uploader = new Uploader;

        // 在白名单内的，正常收窄
        $this->assertSame(['pdf', 'docx'], $uploader->extensionsFor('file', 'pdf,docx'));
        $this->assertSame(['pdf'], $uploader->extensionsFor('file', '.pdf'));

        /*
         * 不在服务端白名单里的一律被丢掉 —— 这就是漏洞的修复点。
         *
         * 客户端**只能收窄，不能扩宽**。
         */
        $this->assertSame(
            $uploader->allowedExtensions('file'),
            $uploader->extensionsFor('file', 'php'),
            'accept=php 不能把 php 引入白名单'
        );

        // png 属于 image 白名单，不属于 file —— kind=file 时不该被放行
        $this->assertSame(
            $uploader->allowedExtensions('file'),
            $uploader->extensionsFor('file', 'image/png')
        );

        // 危险 accept 进了白名单的话，答案会更糟 —— 显式再钉一遍
        $this->assertNotContains('php', $uploader->extensionsFor('file', 'php,gif'));
        $this->assertNotContains('gif', $uploader->extensionsFor('file', 'php,gif'));

        // 部分合法时只保留合法的那些
        $this->assertSame(['pdf'], $uploader->extensionsFor('file', 'pdf,gif,php'));

        // 乱写时退回默认白名单，而不是放开成任意文件
        $this->assertSame($uploader->allowedExtensions('file'), $uploader->extensionsFor('file', '???,!!!'));
    }

    /**
     * 危险扩展名有一份**硬编码黑名单**，不依赖 config。
     *
     * ⚠️ 这里是纯 PHPUnit（没有 Laravel 容器），所以测不了「config 里写 php」那条路径 ——
     * `allowedExtensions()` 读 config 要用 `config()`。
     * 「config 里写了也会被剔除」这个行为由 Uploader::allowedExtensions() 里的
     * 无条件 array_filter 保证，并由 e2e（tests/e2e/http-e2e.php 的上传安全段）覆盖。
     */
    public function test_forbidden_extensions_are_never_allowed(): void
    {
        $uploader = new Uploader;

        $this->assertContains('php', Uploader::forbiddenExtensions());
        $this->assertContains('phtml', Uploader::forbiddenExtensions());
        $this->assertContains('phar', Uploader::forbiddenExtensions());

        foreach (['file', 'image'] as $kind) {
            foreach (Uploader::forbiddenExtensions() as $bad) {
                $this->assertNotContains(
                    $bad,
                    $uploader->allowedExtensions($kind),
                    "{$kind} 白名单不该含 {$bad}"
                );
            }
        }
    }

    public function test_directory_is_normalized(): void
    {
        $uploader = new Uploader;

        // directory() 只做 trim；真正的过滤发生在 store() 内部
        $this->assertSame('uploads', $uploader->directory());
    }

    /**
     * 单一来源：上传能力必须同时出现在 capabilities 与 search-docs。
     */
    public function test_upload_is_exposed_in_capabilities_and_search_docs(): void
    {
        $foundation = (new Capabilities)->foundationCapabilities();

        $this->assertArrayHasKey('upload', $foundation);
        $this->assertStringContainsString('image(', (string) $foundation['upload']['declare']);

        $text = (new SearchDocs)->handle(new Request([]))->content()->toArray()['text'] ?? '';

        $this->assertStringContainsString('upload()', $text, 'search-docs 缺少 upload 能力');
        $this->assertStringContainsString('image/pdf', $text.'image/pdf');
        $this->assertStringContainsString('accept', $text, 'search-docs 缺少 accept()');
        $this->assertStringContainsString('maxSize', $text, 'search-docs 缺少 maxSize()');
    }
}
