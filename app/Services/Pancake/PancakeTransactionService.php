<?php

namespace App\Services\Pancake;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

class PancakeTransactionService extends PancakeClient
{
    public function getTransactions($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/transactions", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch transactions: ' . $response->body());
        }

        $data = $response->json();
        return new LengthAwarePaginator($data['data'] ?? [], $data['total_entries'] ?? 0, $limit, $page);
    }

    public function createTransaction($data)
    {
        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/transactions?api_key={$this->apiKey}", $data);
        if ($response->failed()) {
            throw new \Exception('Failed to create transaction: ' . $response->body());
        }
        return $response->json()['data'] ?? $response->json();
    }
}
