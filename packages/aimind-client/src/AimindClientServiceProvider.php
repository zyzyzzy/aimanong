<?php

declare(strict_types=1);

namespace Aimanong\AimindClient;

use Aimanong\AimindClient\McpTools\AskPeerTool;
use Aimanong\AimindClient\McpTools\GetSolutionsTool;
use Aimanong\AimindClient\McpTools\GetSuggestionsTool;
use Aimanong\AimindClient\McpTools\MarkOutdatedTool;
use Aimanong\AimindClient\McpTools\RateAnswerTool;
use Aimanong\AimindClient\McpTools\ReportBugTool;
use Aimanong\AimindClient\McpTools\SearchKnowledgeTool;
use Aimanong\AimindClient\McpTools\ShareExperienceTool;
use Aimanong\Mcp\ToolRegistry;
use Illuminate\Support\ServiceProvider;

class AimindClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/aimind.php', 'aimind');

        $this->app->singleton(AimindClient::class, function () {
            return new AimindClient(
                baseUrl: rtrim((string) config('aimind.url', 'http://127.0.0.1:8999'), '/'),
                apiKey: config('aimind.api_key'),
                timeout: (int) config('aimind.timeout', 10),
            );
        });
    }

    public function boot(): void
    {
        if (! config('aimind.enabled', true)) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/aimind.php' => config_path('aimind.php'),
        ], 'aimind-config');

        /*
         * 把工具注册进 Aimanong 的 MCP Server。
         *
         * ⚠️ 原先这里写的是 `Laravel\Mcp\Server\Registry::addTools()` ——
         * 那个类**不存在**（真实类名是 Registrar，而且它注册的是 server 不是 tool）。
         * `class_exists` 守卫于是永远为 false，**工具从来没被注册过**，
         * 而且不报错。这正是本项目最反对的「静默失效」。
         *
         * 现在用 Aimanong 自己的 ToolRegistry，AimanongServer 构造时合并。
         */
        if (class_exists(ToolRegistry::class)) {
            ToolRegistry::register(
                SearchKnowledgeTool::class,
                AskPeerTool::class,
                ShareExperienceTool::class,
                GetSolutionsTool::class,
                RateAnswerTool::class,
                ReportBugTool::class,
                GetSuggestionsTool::class,
                MarkOutdatedTool::class,
            );
        }
    }
}
