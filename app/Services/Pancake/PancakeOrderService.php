<?php

namespace App\Services\Pancake;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

class PancakeOrderService extends PancakeClient
{
    public function getOrders($page = 1, $limit = 15, $search = null, $status = null)
    {
        $queryParams = [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit,
        ];

        if ($search) {
            $queryParams['search'] = $search;
        }

        if ($status) {
            $queryParams['filter_status'] = is_array($status) ? $status : [$status];
        }

        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/orders", $queryParams);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch orders from Pancake: ' . $response->body());
        }

        $data = $response->json();

        $orders = collect($data['data'] ?? [])->map(function ($item) {
            return $this->mapOrder($item);
        });

        $total = $data['total_entries'] ?? 0;

        return new LengthAwarePaginator(
            $orders,
            $total,
            $limit,
            $page,
            ['path' => url('api/admin/orders')]
        );
    }

    public function getOrder($id)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/orders/{$id}", [
            'api_key' => $this->apiKey
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch order from Pancake: ' . $response->body());
        }

        $data = $response->json();
        $orderData = $data['data'] ?? $data;

        if (!isset($orderData['id'])) {
            throw new \Exception('API response missing ID. Response: ' . json_encode($data));
        }

        return $this->mapOrder($orderData);
    }

    public function createOrder($data)
    {
        $payload = [
            'bill_full_name' => $data['customer_name'] ?? 'Guest',
            'bill_phone_number' => $data['customer_phone'] ?? '',
            'bill_address' => $data['shipping_address'] ?? '',
            'bill_email' => $data['customer_email'] ?? '',
            'items' => collect($data['items'] ?? [])->map(function ($item) {
                return [
                    'variation_id' => $item['variation_id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'price' => $item['price'] ?? 0,
                ];
            })->toArray(),
            'total_amount' => $data['total_amount'] ?? 0,
            'note' => $data['note'] ?? '',
        ];

        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/orders?api_key={$this->apiKey}", $payload);

        if ($response->failed()) {
            throw new \Exception('Failed to create order on Pancake: ' . $response->body());
        }

        $responseData = $response->json();
        return $this->mapOrder($responseData['data'] ?? $responseData);
    }

    public function updateOrder($id, $data)
    {
        $payload = [
            'bill_full_name' => $data['customer_name'] ?? null,
            'note' => $data['note'] ?? null,
        ];

        $payload = array_filter($payload, function ($v) {
            return !is_null($v);
        });

        $response = Http::put("{$this->baseUrl}/shops/{$this->shopId}/orders/{$id}?api_key={$this->apiKey}", $payload);

        if ($response->failed()) {
            throw new \Exception('Failed to update order on Pancake: ' . $response->body());
        }

        $responseData = $response->json();
        return $this->mapOrder($responseData['data'] ?? $responseData);
    }

    protected function mapOrder($pancakeOrder)
    {
        $address = $pancakeOrder['shipping_address'] ?? '';
        if (is_array($address)) {
            $parts = [
                $address['address'] ?? '',
                $address['commune_name'] ?? '',
                $address['district_name'] ?? '',
                $address['province_name'] ?? ''
            ];
            $address = $address['full_address'] ?? implode(', ', array_filter($parts));

            if (empty($address) && !empty($parts)) {
                $address = implode(', ', array_filter($parts));
            }
        }

        return [
            'id' => $pancakeOrder['id'],
            'shop_id' => $pancakeOrder['shop_id'] ?? null,
            'customer_name' => $pancakeOrder['bill_full_name'] ?? 'Unknown',
            'customer_email' => $pancakeOrder['bill_email'] ?? '',
            'customer_phone' => $pancakeOrder['bill_phone_number'] ?? '',
            'shipping_address' => $address,

            'total_amount' => $pancakeOrder['total_amount'] ?? 0,

            'status' => $pancakeOrder['status_name'] ?? ($pancakeOrder['status'] ?? 'pending'),
            'payment_status' => $pancakeOrder['payment_type'] ?? ($pancakeOrder['payment_status'] ?? 'unpaid'),
            'payment_method' => $pancakeOrder['payment_method'] ?? ($pancakeOrder['payment_type'] ?? 'cod'),
            'transaction_id' => $pancakeOrder['transaction_id'] ?? null,

            'created_at' => $pancakeOrder['inserted_at'] ?? null,

            'items' => collect($pancakeOrder['items'] ?? [])->map(function ($item) {
                $variationInfo = $item['variation_info'] ?? [];

                $image = null;
                if (is_array($variationInfo) && !empty($variationInfo['images'])) {
                    $image = $variationInfo['images'][0] ?? null;
                }

                $variationString = $item['variation_name'] ?? '';
                if (empty($variationString)) {
                    if (is_string($variationInfo)) {
                        $variationString = $variationInfo;
                    } elseif (is_array($variationInfo)) {
                        $variationString = $variationInfo['name'] ?? ($variationInfo['value'] ?? '');
                    }
                }

                $productName = $item['product_name'] ?? '';
                if (empty($productName) || $productName === 'Unknown Item') {
                    $productName = is_array($variationInfo) ? ($variationInfo['name'] ?? 'Unknown Item') : 'Unknown Item';
                }

                $price = $item['price'] ?? 0;
                if (empty($price) && is_array($variationInfo)) {
                    $price = $variationInfo['retail_price'] ?? 0;
                }

                return [
                    'product_name' => $productName,
                    'quantity' => $item['quantity'] ?? 0,
                    'price' => $price,
                    'variation_info' => $variationString,
                    'image' => $image,
                ];
            }),
            'shipping_fee' => $pancakeOrder['shipping_fee'] ?? 0,
            'cod' => $pancakeOrder['cod'] ?? 0,
            'partner' => $pancakeOrder['partner'] ?? null,
            'note' => $pancakeOrder['note'] ?? '',
        ];
    }
}
