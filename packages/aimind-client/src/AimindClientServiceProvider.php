<?php

declare(strict_types=1);

namespace Aimanong\AimindClient;

use Aimanong\AimindClient\McpTools\AskPeerTool;
use Aimanong\AimindClient\McpTools\GetSolutionsTool;
use Aimanong\AimindClient\McpTools\RateAnswerTool;
use Aimanong\AimindClient\McpTools\ReportBugTool;
use Aimanong\AimindClient\McpTools\SearchKnowledgeTool;
use Aimanong\AimindClient\McpTools\ShareExperienceTool;
use Illuminate\Support\ServiceProvider;

class AimindClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/aimind.php', 'aimind');

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
            __DIR__ . '/../config/aimind.php' => config_path('aimind.php'),
        ], 'aimind-config');

        // 注册 MCP 工具到 Laravel MCP Server
        if (class_exists(\Laravel\Mcp\Server\Registry::class)) {
            \Laravel\Mcp\Server\Registry::addTools([
                SearchKnowledgeTool::class,
                AskPeerTool::class,
                ShareExperienceTool::class,
                GetSolutionsTool::class,
                RateAnswerTool::class,
                ReportBugTool::class,
            ]);
        }
    }
}
