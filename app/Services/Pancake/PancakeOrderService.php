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
        $subtotal = (float) data_get($data, 'subtotal', 0);
        $shippingFee = (float) data_get($data, 'shipping_fee', 0);
        $discountAmount = (float) data_get($data, 'discount_amount', 0);
        $totalAmount = $subtotal + $shippingFee - $discountAmount;

        $payload = [
            'bill_full_name' => data_get($data, 'customer_name', 'Guest'),
            'bill_phone_number' => data_get($data, 'customer_phone', ''),
            'bill_address' => data_get($data, 'shipping_address', ''),
            'shipping_address' => data_get($data, 'shipping_address', ''),
            'bill_email' => data_get($data, 'customer_email', ''),
            'items' => collect(data_get($data, 'items', []))->map(function ($item) {
                return [
                    'variation_id' => data_get($item, 'variation_id'),
                    'product_id' => data_get($item, 'product_id'),
                    'quantity' => (int) data_get($item, 'quantity', 1),
                    'price' => (int) round((float) data_get($item, 'price', 0)),
                ];
            })->toArray(),
            'total_price' => (int) round($subtotal),
            'shipping_fee' => (int) round($shippingFee),
            'total_discount' => (int) round($discountAmount),
            'discount' => (int) round($discountAmount),
            'money_to_collect' => (int) round($totalAmount),
            'cod' => (int) round($totalAmount),
            'note' => data_get($data, 'note', ''),
        ];

        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/orders?api_key={$this->apiKey}", $payload);

        if ($response->failed()) {
            throw new \Exception('Failed to create order on Pancake: ' . $response->body());
        }

        $responseData = $response->json();

        $orderResponse = $responseData['data'] ?? $responseData;
        
        // Ensure the response returned to the frontend reflects the actual totals sent
        // as sometimes Pancake API response might have different field names or delayed processing
        $orderResponse['total_discount'] = $discountAmount;
        $orderResponse['shipping_fee'] = $shippingFee;
        $orderResponse['money_to_collect'] = $totalAmount;
        $orderResponse['cod'] = $totalAmount;
        $orderResponse['total_price'] = $subtotal;

        return $this->mapOrder($orderResponse);
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

        $address = $pancakeOrder['shipping_address'] ?? $pancakeOrder['bill_address'] ?? '';
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

        // Subtotal calculation logic
        $subtotal = (float) ($pancakeOrder['sub_total'] ?? $pancakeOrder['total_price'] ?? 0);
        
        $itemsRaw = collect($pancakeOrder['items'] ?? []);
        $itemsSum = $itemsRaw->sum(fn($i) => ($i['price'] ?? 0) * ($i['quantity'] ?? 0));
        
        if ($subtotal <= 0) {
            $subtotal = $itemsSum;
        }

        // Discount calculation logic
        $discount = (float) ($pancakeOrder['total_discount'] ?? $pancakeOrder['discount'] ?? $pancakeOrder['promotion_discount'] ?? 0);
        
        // Shipping Fee
        $shipping = (float) ($pancakeOrder['shipping_fee'] ?? 0);

        // Final total (Money to collect)
        $moneyToCollect = (float) ($pancakeOrder['money_to_collect'] ?? $pancakeOrder['cod'] ?? 0);
        
        // --- FALLBACK CALCULATION LOGIC ---
        // 1. If money to collect is 0, calculate it
        if ($moneyToCollect <= 0) {
            $moneyToCollect = max(0, $subtotal + $shipping - $discount);
        }
        
        // 2. If discount is 0 but there's a gap between (subtotal + shipping) and grand total, 
        // it might be an inferred discount.
        if ($discount <= 0 && $moneyToCollect > 0 && ($subtotal + $shipping) > $moneyToCollect) {
            $discount = ($subtotal + $shipping) - $moneyToCollect;
        }

        return [
            'id' => $pancakeOrder['id'] ?? null,
            'shop_id' => $pancakeOrder['shop_id'] ?? null,
            'customer_name' => $pancakeOrder['bill_full_name'] ?? $pancakeOrder['customer_name'] ?? 'Khách hàng',
            'customer_email' => $pancakeOrder['bill_email'] ?? $pancakeOrder['customer_email'] ?? '',
            'customer_phone' => $pancakeOrder['bill_phone_number'] ?? $pancakeOrder['customer_phone'] ?? '',
            'shipping_address' => $address,

            'total_amount' => $subtotal,
            'discount_amount' => $discount,
            'shipping_fee' => $shipping,
            'grand_total' => $moneyToCollect,
            'cod' => $moneyToCollect,

            'status' => $pancakeOrder['status_name'] ?? ($pancakeOrder['status'] ?? 'pending'),
            'payment_status' => $pancakeOrder['payment_type'] ?? ($pancakeOrder['payment_status'] ?? 'unpaid'),
            'payment_method' => $pancakeOrder['payment_method'] ?? ($pancakeOrder['payment_type'] ?? 'cod'),
            'transaction_id' => $pancakeOrder['transaction_id'] ?? null,

            'created_at' => $pancakeOrder['inserted_at'] ?? $pancakeOrder['created_at'] ?? null,

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
            'partner' => $pancakeOrder['partner'] ?? null,
            'note' => $pancakeOrder['note'] ?? '',
        ];
    }
}
