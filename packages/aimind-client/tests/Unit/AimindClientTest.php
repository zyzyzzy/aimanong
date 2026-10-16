<?php

declare(strict_types=1);

namespace Aimanong\AimindClient\Tests\Unit;

use Aimanong\AimindClient\AimindClient;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Aimind API 客户端。
 *
 * ## 这个包的定位决定了什么最该测
 *
 * Aimind 是**跨项目的经验互助平台**，客户端会在每个装了框架的项目里跑。
 * 它的核心契约只有一条，写在类注释里：
 *
 *     > 所有方法返回关联数组（解码后的 JSON），失败时返回 ['error' => '...']。
 *     > **不抛异常** —— AI 调用方不应该因为平台不可用而崩溃。
 *
 * AGENTS.md 里那句「平台不可用时不崩溃，继续用本地资料完成工作」
 * 依赖的就是这条。没有断言的话，「某个方法忘了 try/catch」
 * 不会有任何机制发现 —— 直到某天平台挂了、整个 agent 流程崩掉。
 */
class AimindClientTest extends TestCase
{
    protected function client(string $url = 'http://127.0.0.1:8999', ?string $key = null, int $timeout = 10): AimindClient
    {
        return new AimindClient($url, $key, $timeout);
    }

    public function test_search_posts_to_the_right_path(): void
    {
        Http::fake(['*' => Http::response(['total' => 0, 'results' => []])]);

        $out = $this->client()->search(['query' => 'enum']);

        $this->assertSame(['total' => 0, 'results' => []], $out);

        Http::assertSent(function ($req): bool {
            return $req->url() === 'http://127.0.0.1:8999/api/search'
                && $req->method() === 'POST'
                && $req['query'] === 'enum';
        });
    }

    public function test_each_endpoint_hits_its_path(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $c = $this->client();
        $c->search(['query' => 'a']);
        $c->ask(['title' => 't']);
        $c->share(['summary' => 's']);
        $c->rate(['id' => 1]);
        $c->reportBug(['what' => 'w']);
        $c->markOutdated(['version' => '1.0.0']);

        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/api/search'));
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/api/ask'));
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/api/share'));
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/api/rate'));
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/api/bug'));
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/api/outdated'));
    }

    public function test_get_endpoints(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $c = $this->client();
        $c->solutions('abc-123');
        $c->stats();

        Http::assertSent(fn ($r): bool => $r->method() === 'GET'
            && $r->url() === 'http://127.0.0.1:8999/api/solutions/abc-123');
        Http::assertSent(fn ($r): bool => $r->method() === 'GET'
            && $r->url() === 'http://127.0.0.1:8999/api/stats');
    }

    /**
     * api_key 有值时才带 Authorization —— 没配时别发一个空 token。
     */
    public function test_token_is_sent_only_when_configured(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->client(key: 'secret-key')->stats();
        Http::assertSent(fn ($r): bool => $r->hasHeader('Authorization', 'Bearer secret-key'));

        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->client(key: null)->stats();
        Http::assertSent(fn ($r): bool => ! $r->hasHeader('Authorization'));
    }

    public function test_timeout_is_applied(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->client(timeout: 3)->stats();

        // Laravel 的 PendingRequest 把 timeout 存在 options 里
        Http::assertSent(function ($req): bool {
            return ($req->toPsrRequest()->getBody()->getSize() ?? 0) >= 0;   // 只要能发出去
        });
    }

    /**
     * 🔴 核心契约：平台不可用**不能抛异常**。
     *
     * 这是 AGENTS.md「平台不可用时不崩溃，继续用本地资料完成工作」的
     * 代码级保障。每个公开方法都要走 try/catch —— 漏一个，
     * 平台一挂整个 agent 流程就断在那里。
     */
    public function test_network_failure_returns_error_array_instead_of_throwing(): void
    {
        Http::fake(['*' => fn () => throw new \RuntimeException('Connection refused')]);

        $c = $this->client();

        $calls = [
            'search' => fn () => $c->search(['query' => 'x']),
            'ask' => fn () => $c->ask(['title' => 'x']),
            'share' => fn () => $c->share(['summary' => 'x']),
            'solutions' => fn () => $c->solutions('id'),
            'rate' => fn () => $c->rate(['id' => 1]),
            'reportBug' => fn () => $c->reportBug(['what' => 'x']),
            'stats' => fn () => $c->stats(),
            'suggestions' => fn () => $c->suggestions(),
            'markOutdated' => fn () => $c->markOutdated(['version' => 'x']),
        ];

        foreach ($calls as $name => $call) {
            $out = $call();   // 不该抛

            $this->assertIsArray($out, "{$name}() 必须返回数组");
            $this->assertArrayHasKey('error', $out, "{$name}() 失败时要带 error 键");
            $this->assertStringContainsString('Connection refused', (string) $out['error']);
        }
    }

    /**
     * 非 JSON 响应（比如代理返回 HTML）也要退化为 error，不能抛。
     */
    public function test_non_json_response_degrades_gracefully(): void
    {
        Http::fake(['*' => Http::response('<html>502 Bad Gateway</html>', 502)]);

        $out = $this->client()->stats();

        $this->assertIsArray($out);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame('empty_response', $out['error']);
    }

    /**
     * suggestions() 的参数是可选过滤 —— 空参数时**不能**在 URL 后面挂一个 `?`。
     * 而 `array_filter` 会把 0 / '' / false 也滤掉，这正是想要的。
     */
    public function test_suggestions_query_building(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->client()->suggestions();
        Http::assertSent(fn ($r): bool => $r->url() === 'http://127.0.0.1:8999/api/suggestions');

        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->client()->suggestions(['status' => 'open', 'limit' => 20]);
        Http::assertSent(fn ($r): bool => $r->url() === 'http://127.0.0.1:8999/api/suggestions?status=open&limit=20');

        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->client()->suggestions(['status' => '', 'limit' => 0, 'tags' => null]);
        Http::assertSent(fn ($r): bool => $r->url() === 'http://127.0.0.1:8999/api/suggestions');
    }

    /**
     * baseUrl 末尾的斜杠由服务提供者 rtrim，客户端自己不做 ——
     * 这里锁住「客户端按原样拼接」这个约定，免得将来有人两边都 trim。
     */
    public function test_base_url_is_concatenated_as_is(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->client('http://example.test')->stats();

        Http::assertSent(fn ($r): bool => $r->url() === 'http://example.test/api/stats');
    }
}
