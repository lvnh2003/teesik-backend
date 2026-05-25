<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;

class StatisticController extends Controller
{
    public function sales(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->startOfDay());
        $endDate = $request->input('end_date', now()->endOfDay());

        $orders = Order::query()->whereBetween('created_at', [$startDate, $endDate]);

        return $this->successResponse([
            'total_revenue' => (float) (clone $orders)->sum('grand_total'),
            'total_orders' => (clone $orders)->count(),
            'source' => 'local_database',
        ]);
    }

    public function inventory(Request $request)
    {
        $products = Product::query()->where('is_active', true)->get();
        $totalStock = 0;
        $totalValue = 0;

        foreach ($products as $product) {
            $variations = $product->variations ?? [];
            if (empty($variations)) {
                $totalStock++;
                $totalValue += $product->price;
                continue;
            }

            foreach ($variations as $variation) {
                $stock = (int) ($variation['stock_quantity'] ?? 0);
                $price = (float) ($variation['price'] ?? $product->price);
                $totalStock += $stock;
                $totalValue += $stock * $price;
            }
        }

        return $this->successResponse([
            'total_products' => $products->count(),
            'total_stock' => $totalStock,
            'total_value' => $totalValue,
            'source' => 'local_database',
        ]);
    }

    public function dashboard()
    {
        $recentOrders = Order::query()->latest()->limit(6)->get()->map(fn(Order $order) => $order->toApiArray());

        return $this->successResponse([
            'total_products' => Product::query()->where('is_active', true)->count(),
            'total_users' => \App\Models\User::count(),
            'total_orders' => Order::query()->count(),
            'recent_orders' => $recentOrders->values(),
            'revenue' => [
                'daily' => 0,
                'weekly' => 0,
                'monthly' => $recentOrders->sum(fn($order) => (float) ($order['grand_total'] ?? $order['total_amount'] ?? 0)),
            ],
        ]);
    }
}
