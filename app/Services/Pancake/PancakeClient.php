<?php

namespace App\Services\Pancake;

use Illuminate\Support\Facades\Http;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class PancakeClient
{
    protected string $apiKey;
    protected string $shopId;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('pancake.api_key');
        $this->shopId = config('pancake.shop_id');
        $this->baseUrl = config('pancake.base_url', 'https://pos.pages.fm/api/v1');
    }

    protected function shopUrl(string $endpoint): string
    {
        return "{$this->baseUrl}/shops/{$this->shopId}{$endpoint}";
    }

    protected function apiParams(): array
    {
        return ['api_key' => $this->apiKey];
    }

    protected function getWithPaginator(string $url, array $queryParams): LengthAwarePaginator
    {
        $page = $queryParams['page_number'] ?? 1;
        $limit = $queryParams['page_size'] ?? 15;

        $response = Http::get($url, $queryParams);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch data from Pancake: ' . $response->body());
        }

        $data = $response->json();
        $items = $data['data'] ?? [];
        $total = $data['total_entries'] ?? 0;

        return new LengthAwarePaginator($items, $total, $limit, $page);
    }

    protected function get(string $endpoint, array $params = []): array
    {
        $url = $this->shopUrl($endpoint);
        $response = Http::timeout(config('pancake.timeout', 30))->get($url, $params);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch data from Pancake: ' . $response->body());
        }

        return $response->json();
    }

    protected function post(string $endpoint, array $data = []): array
    {
        $url = $this->shopUrl($endpoint) . '?api_key=' . $this->apiKey;
        $response = Http::timeout(config('pancake.timeout', 30))->post($url, $data);

        if ($response->failed()) {
            throw new \Exception('Failed to post data to Pancake: ' . $response->body());
        }

        return $response->json();
    }

    protected function put(string $endpoint, array $data = []): array
    {
        $url = $this->shopUrl($endpoint) . '?api_key=' . $this->apiKey;
        $response = Http::timeout(config('pancake.timeout', 30))->put($url, $data);

        if ($response->failed()) {
            throw new \Exception('Failed to update data on Pancake: ' . $response->body());
        }

        return $response->json();
    }

    protected function deleteRequest(string $endpoint): array
    {
        $url = $this->shopUrl($endpoint) . '?api_key=' . $this->apiKey;
        $response = Http::timeout(config('pancake.timeout', 30))->delete($url);

        if ($response->failed()) {
            throw new \Exception('Failed to delete data on Pancake: ' . $response->body());
        }

        return $response->json();
    }
}
