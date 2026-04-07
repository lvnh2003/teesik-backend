<?php

namespace App\Services\Pancake;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

class PancakeWarehouseService extends PancakeClient
{
    public function getWarehouses()
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/warehouses", [
            'api_key' => $this->apiKey
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch warehouses: ' . $response->body());
        }

        $data = $response->json();
        return $data['data'] ?? $data;
    }

    public function getInventoryHistories($page = 1, $limit = 30, $warehouseId = null)
    {
        $queryParams = [
            'api_key' => $this->apiKey,
            'page' => $page,
            'page_size' => $limit,
        ];

        if ($warehouseId) {
            $queryParams['warehouse_id'] = $warehouseId;
        }

        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/inventory_histories", $queryParams);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch inventory history: ' . $response->body());
        }

        $data = $response->json();

        $histories = $data['data'] ?? [];
        $total = $data['total_entries'] ?? 0;

        return new LengthAwarePaginator(
            $histories,
            $total,
            $limit,
            $page,
            ['path' => url('api/admin/inventory-history')]
        );
    }
}
