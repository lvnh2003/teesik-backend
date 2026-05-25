<?php

namespace App\Repositories;

use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

class LocalOrderRepository
{
    public function paginate(int $page = 1, int $limit = 15, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        $query = Order::query();

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('pancake_id', 'like', "%{$search}%");
            });
        }

        $paginator = $query->latest()->paginate($limit, ['*'], 'page', $page);

        return new LengthAwarePaginator(
            $paginator->getCollection()->map(fn(Order $order) => $order->toApiArray())->values(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function paginateForUser($user, int $page = 1, int $limit = 15, ?string $search = null, ?string $status = null): LengthAwarePaginator
    {
        $query = Order::query()->where(function ($q) use ($user) {
            $q->where('user_id', $user->id);

            if ($user->phone) {
                $q->orWhere('customer_phone', $user->phone);
            }

            if ($user->email) {
                $q->orWhere('customer_email', $user->email);
            }
        });

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('pancake_id', 'like', "%{$search}%");
            });
        }

        $paginator = $query->latest()->paginate($limit, ['*'], 'page', $page);

        return new LengthAwarePaginator(
            $paginator->getCollection()->map(fn(Order $order) => $order->toApiArray())->values(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function find($id): ?array
    {
        $order = Order::query()
            ->where('id', $id)
            ->orWhere('pancake_id', (string) $id)
            ->first();

        return $order?->toApiArray();
    }

    public function createCheckoutOrder(array $data, $user = null): array
    {
        $subtotal = (float) data_get($data, 'subtotal', 0);
        $shippingFee = (float) data_get($data, 'shipping_fee', 0);
        $discountAmount = (float) data_get($data, 'discount_amount', 0);
        $grandTotal = max(0, $subtotal + $shippingFee - $discountAmount);

        $order = Order::create([
            'user_id' => $user?->id,
            'customer_name' => data_get($data, 'customer_name', 'Guest'),
            'customer_email' => data_get($data, 'customer_email', ''),
            'customer_phone' => data_get($data, 'customer_phone', ''),
            'shipping_address' => data_get($data, 'shipping_address', ''),
            'total_amount' => $subtotal,
            'discount_amount' => $discountAmount,
            'shipping_fee' => $shippingFee,
            'grand_total' => $grandTotal,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_method' => strtolower(data_get($data, 'payment_method', 'cod')),
            'items' => collect(data_get($data, 'items', []))->map(fn($item) => [
                'product_id' => data_get($item, 'product_id'),
                'product_variant_id' => data_get($item, 'variation_id'),
                'product_name' => data_get($item, 'name', 'Product'),
                'quantity' => (int) data_get($item, 'quantity', 1),
                'price' => (float) data_get($item, 'price', 0),
                'variation_info' => data_get($item, 'attributes', []),
            ])->toArray(),
            'data' => [
                'note' => data_get($data, 'note', ''),
                'source' => 'local_checkout',
            ],
        ]);

        return $order->toApiArray();
    }

    public function upsertFromPancake(array $order): Order
    {
        $pancakeId = (string) ($order['id'] ?? '');
        if ($pancakeId === '') {
            throw new \InvalidArgumentException('Order is missing id.');
        }

        return Order::updateOrCreate(
            ['pancake_id' => $pancakeId],
            [
                'customer_name' => $order['customer_name'] ?? 'Khách hàng',
                'customer_email' => $order['customer_email'] ?? '',
                'customer_phone' => $order['customer_phone'] ?? '',
                'shipping_address' => $order['shipping_address'] ?? '',
                'total_amount' => (float) ($order['total_amount'] ?? 0),
                'discount_amount' => (float) ($order['discount_amount'] ?? 0),
                'shipping_fee' => (float) ($order['shipping_fee'] ?? 0),
                'grand_total' => (float) ($order['grand_total'] ?? $order['cod'] ?? 0),
                'status' => $order['status'] ?? 'pending',
                'payment_status' => $order['payment_status'] ?? 'unpaid',
                'payment_method' => $order['payment_method'] ?? 'cod',
                'transaction_id' => $order['transaction_id'] ?? null,
                'items' => $order['items'] ?? [],
                'data' => $order,
                'synced_at' => now(),
            ]
        );
    }
}
