<?php

namespace App\Services\Pancake;

use Illuminate\Support\Facades\Http;

class PancakeAnalyticsService extends PancakeClient
{
    public function getSalesAnalytics($startDate, $endDate)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/analytics/sale", [
            'api_key' => $this->apiKey,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch sales analytics: ' . $response->body());
        }
        return $response->json()['data'] ?? $response->json();
    }

    public function getInventoryAnalytics()
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/inventory_analytics/inventory", [
            'api_key' => $this->apiKey
        ]);
        if ($response->failed()) {
            throw new \Exception('Failed to fetch inventory analytics: ' . $response->body());
        }
        return $response->json()['data'] ?? $response->json();
    }

    public function formatAnalyticsData($data)
    {
        return $data;
    }

    public function formatCurrency($amount, $currency = 'VND')
    {
        return number_format($amount) . ' ' . $currency;
    }
}
