<?php

namespace App\Services\Pancake;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

class PancakeMarketingService extends PancakeClient
{
    public function getPromotions($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/promotion_advance", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch promotions: ' . $response->body());
        }

        $data = $response->json();
        $items = $this->formatMarketingDates($data['data'] ?? []);
        return new LengthAwarePaginator($items, $data['total_entries'] ?? 0, $limit, $page);
    }

    public function getVouchers($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/vouchers", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch vouchers: ' . $response->body());
        }

        $data = $response->json();
        $items = $this->formatMarketingDates($data['data'] ?? []);
        return new LengthAwarePaginator($items, $data['total_entries'] ?? 0, $limit, $page);
    }

    public function getCombos($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/combo_products", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch combos: ' . $response->body());
        }

        $data = $response->json();
        $items = $this->formatMarketingDates($data['data'] ?? []);
        return new LengthAwarePaginator($items, $data['total_entries'] ?? 0, $limit, $page);
    }

    public function createCombo($data)
    {
        $startTime = isset($data['start_date']) && !empty($data['start_date']) ? strtotime($data['start_date']) : 1;
        $endTime = isset($data['end_date']) && !empty($data['end_date']) ? strtotime($data['end_date']) : 1;

        $payload = [
            'combo_product' => [
                'name' => $data['name'] ?? 'New Combo',
                'currency' => 'VND',
                'start_time' => $startTime,
                'end_time' => $endTime,
                'is_value_combo' => true,
                'value_combo' => isset($data['price']) && is_numeric($data['price']) ? (int) $data['price'] : 0,
                'is_use_percent' => false,
                'is_variation' => false,
                'variations' => []
            ]
        ];

        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/combo_products?api_key={$this->apiKey}", $payload);
        if ($response->failed()) {
            throw new \Exception('Failed to create combo: ' . $response->body());
        }
        return $response->json()['data'] ?? $response->json();
    }

    public function updateCombo($id, $data)
    {
        $startTime = isset($data['start_date']) && !empty($data['start_date']) ? strtotime($data['start_date']) : 1;
        $endTime = isset($data['end_date']) && !empty($data['end_date']) ? strtotime($data['end_date']) : 1;

        $data['start_time'] = $startTime;
        $data['end_time'] = $endTime;
        $data['value_combo'] = isset($data['price']) && is_numeric($data['price']) ? (int) $data['price'] : ($data['value_combo'] ?? 0);
        $data['is_activated'] = ($data['status'] ?? 'active') === 'active';

        unset($data['start_date']);
        unset($data['end_date']);
        unset($data['price']);
        unset($data['status']);

        $payload = ['combo_product' => $data];

        $response = Http::put("{$this->baseUrl}/shops/{$this->shopId}/combo_products/{$id}?api_key={$this->apiKey}", $payload);
        if ($response->failed()) {
            throw new \Exception('Failed to update combo: ' . $response->body());
        }
        return $response->json()['data'] ?? $response->json();
    }

    public function deleteCombo($id)
    {
        $response = Http::delete("{$this->baseUrl}/shops/{$this->shopId}/combo_products/{$id}?api_key={$this->apiKey}");
        if ($response->failed()) {
            throw new \Exception('Failed to delete combo: ' . $response->body());
        }
        return true;
    }

    private function formatMarketingDates($items)
    {
        return collect($items)->map(function ($item) {
            $startDate = null;
            $endDate = null;

            if (!empty($item['start_time']) && strpos($item['start_time'], '1970-01-01') === false) {
                $startDate = date('Y-m-d\TH:i:s', strtotime($item['start_time']));
            }
            if (!empty($item['end_time']) && strpos($item['end_time'], '1970-01-01') === false) {
                $endDate = date('Y-m-d\TH:i:s', strtotime($item['end_time']));
            }

            $item['start_date'] = $startDate;
            $item['end_date'] = $endDate;
            return $item;
        })->values();
    }
}
