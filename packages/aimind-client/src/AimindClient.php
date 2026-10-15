<?php

declare(strict_types=1);

namespace Aimanong\AimindClient;

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

    /** @return array<string, mixed> */
    public function search(array $params): array
    {
        return $this->post('/api/search', $params);
    }

    /** @return array<string, mixed> */
    public function ask(array $params): array
    {
        return $this->post('/api/ask', $params);
    }

    /** @return array<string, mixed> */
    public function share(array $params): array
    {
        return $this->post('/api/share', $params);
    }

    /** @return array<string, mixed> */
    public function solutions(string $questionId): array
    {
        return $this->get("/api/solutions/{$questionId}");
    }

    /** @return array<string, mixed> */
    public function rate(array $params): array
    {
        return $this->post('/api/rate', $params);
    }

    /** @return array<string, mixed> */
    public function reportBug(array $params): array
    {
        return $this->post('/api/bug', $params);
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return $this->get('/api/stats');
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $data): array
    {
        try {
            $response = $this->http()->post($this->baseUrl . $path, $data);

            return $response->json() ?? ['error' => 'empty_response'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        try {
            $response = $this->http()->get($this->baseUrl . $path);

            return $response->json() ?? ['error' => 'empty_response'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::timeout($this->timeout)->acceptJson();

        if ($this->apiKey) {
            $http = $http->withToken($this->apiKey);
        }

        return $http;
    }
}
