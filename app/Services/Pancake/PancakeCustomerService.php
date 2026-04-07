<?php

namespace App\Services\Pancake;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

class PancakeCustomerService extends PancakeClient
{
    public function getCustomers($page = 1, $limit = 30, $search = null)
    {
        $queryParams = ['api_key' => $this->apiKey, 'page_number' => $page, 'page_size' => $limit];
        if ($search) {
            $queryParams['search'] = $search;
        }

        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/customers", $queryParams);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch customers: ' . $response->body());
        }

        $data = $response->json();
        return new LengthAwarePaginator($data['data'] ?? [], $data['total_entries'] ?? 0, $limit, $page);
    }

    public function createCustomer($data)
    {
        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/customers?api_key={$this->apiKey}", $data);
        if ($response->failed()) {
            throw new \Exception('Failed to create customer: ' . $response->body());
        }
        return $response->json()['data'] ?? $response->json();
    }

    public function updateCustomer($id, $data)
    {
        $response = Http::put("{$this->baseUrl}/shops/{$this->shopId}/customers/{$id}?api_key={$this->apiKey}", $data);
        if ($response->failed()) {
            throw new \Exception('Failed to update customer: ' . $response->body());
        }
        return $response->json()['data'] ?? $response->json();
    }
}
