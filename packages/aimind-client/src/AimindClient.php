<?php

declare(strict_types=1);

namespace Aimanong\AimindClient;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Aimind API 客户端。
 *
 * 所有方法返回关联数组（解码后的 JSON），失败时返回 ['error' => '...']。
 * 不抛异常 —— AI 调用方不应该因为平台不可用而崩溃。
 */
class AimindClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 10,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function search(array $params): array
    {
        return $this->post('/api/search', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function ask(array $params): array
    {
        return $this->post('/api/ask', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function share(array $params): array
    {
        return $this->post('/api/share', $params);
    }

    /** @return array<string, mixed> */
    public function solutions(string $questionId): array
    {
        return $this->get("/api/solutions/{$questionId}");
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function rate(array $params): array
    {
        return $this->post('/api/rate', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function reportBug(array $params): array
    {
        return $this->post('/api/bug', $params);
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return $this->get('/api/stats');
    }

    /**
     * 列出框架改进建议。
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function suggestions(array $params = []): array
    {
        $query = http_build_query(array_filter($params));

        return $this->get('/api/suggestions'.($query ? '?'.$query : ''));
    }

    /**
     * 通知平台「框架发新版了」——标记旧经验待重新验证。
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function markOutdated(array $params): array
    {
        return $this->post('/api/outdated', $params);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function post(string $path, array $data): array
    {
        try {
            $response = $this->http()->post($this->baseUrl.$path, $data);

            return $response->json() ?? ['error' => 'empty_response'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        try {
            $response = $this->http()->get($this->baseUrl.$path);

            return $response->json() ?? ['error' => 'empty_response'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function http(): PendingRequest
    {
        $http = Http::timeout($this->timeout)->acceptJson();

        if ($this->apiKey) {
            $http = $http->withToken($this->apiKey);
        }

        return $http;
    }
}
