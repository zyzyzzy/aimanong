<?php

declare(strict_types=1);

namespace Aimanong\Mcp;

use Aimanong\Mcp\Tools\CreatePage;
use Aimanong\Mcp\Tools\DescribeResource;
use Aimanong\Mcp\Tools\ListResources;
use Aimanong\Mcp\Tools\QueryData;
use Aimanong\Mcp\Tools\RunVerify;
use Aimanong\Mcp\Tools\ScaffoldResource;
use Aimanong\Mcp\Tools\SearchDocs;
use Aimanong\Mcp\Tools\ValidateDeclaration;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * Aimanong MCP Server。
 *
 * 让任何 AI 客户端（Claude / Cursor / ChatGPT）直接操作本框架，
 * 无需读文档 —— 这是 Aimanong 相对传统后台框架的绝对差异化。
 */
class AimanongServer extends Server
{
    protected string $name = 'Aimanong';

    protected string $version = '1.2.0';

    protected string $instructions = <<<'MARKDOWN'
        Aimanong（AI 码农）是一个「AI 优先」的 Laravel 后台框架。

        核心心智模型：一个 Resource = 一张数据表 = 一组后台页面。
        你只需写一个 PHP 类，框架自动产出 API、页面、Schema、文档。
        你永远不写前端代码 —— 前端由 Schema 自动渲染。

        推荐工作流：
        1. list_resources        查看现有 Resource
        2. describe_resource     了解某 Resource 的字段与规则
        3. scaffold_resource     从数据表生成 Resource（推荐）
           或 create_page       直接产出 Resource 代码
        4. validate_declaration  校验产出是否合法
        5. run_verify            跑项目自检

        铁律：
        - 方法签名唯一，不存在重载
        - 不嵌套闭包，全部走链式调用
        - 字段必须是数据库真实字段
        - 生成后必须 validate_declaration
    MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListResources::class,
        DescribeResource::class,
        ScaffoldResource::class,
        CreatePage::class,
        ValidateDeclaration::class,
        RunVerify::class,
        QueryData::class,
        SearchDocs::class,
    ];
}
