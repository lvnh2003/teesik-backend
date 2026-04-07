<?php

namespace App\Services\Pancake;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

class PancakePurchaseService extends PancakeClient
{
    public function getPurchases($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/purchases", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch purchases: ' . $response->body());
        }

        $data = $response->json();
        return new LengthAwarePaginator($data['data'] ?? [], $data['total_entries'] ?? 0, $limit, $page);
    }

    public function createPurchase($data)
    {
        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/purchases?api_key={$this->apiKey}", $data);
        if ($response->failed()) {
            throw new \Exception('Failed to create purchase: ' . $response->body());
        }
        return $response->json()['data'] ?? $response->json();
    }

    public function updatePurchase($id, $data)
    {
        $response = Http::put("{$this->baseUrl}/shops/{$this->shopId}/purchases/{$id}?api_key={$this->apiKey}", $data);
        if ($response->failed()) {
            throw new \Exception('Failed to update purchase: ' . $response->body());
        }
        return $response->json()['data'] ?? $response->json();
    }
}
