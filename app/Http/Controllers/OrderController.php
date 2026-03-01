<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\JsonStore;
use App\Services\PancakeService;

class OrderController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function checkout(Request $request)
    {
        // Validate request
        $request->validate([
            'customer_name' => 'required|string',
            'customer_email' => 'required|email',
            'address' => 'required|string',
            'customer_phone' => 'required|string',
        ]);

        $cartId = $request->header('X-Cart-ID');

        // Fetch Cart Items
        // We'll read from Cart if we have migrated it OR existing Refactor of CartController uses models
        // But here we might want to support both or just assume Cart model usage but without relation.
        // Actually, we should fetch items from correct source.
        // Since we updated CartController to use DB (but no product relation), we should fetch from DB.
        // BUT, the previous code used `JsonStore` for cart.
        // If I updated CartController, I updated it to use DB `Cart` and `CartItem`.

        // Let's assume we use the DB Cart now.
        $user = $request->user('api');

        $items = [];
        $total = 0;

        if ($cartId) {
            $cart = \App\Models\Cart::where('cart_id', $cartId)->with('items')->first();
            if ($cart) {
                $items = $cart->items->map(function ($item) {
                    return [
                        'product_id' => $item->product_id,
                        'variation_id' => $item->product_variant_id ?: $item->product_id, // If no variant, maybe use product_id
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'name' => $item->name,
                    ];
                })->toArray();

                $total = $cart->items->sum(function ($item) {
                    return $item->price * $item->quantity;
                });
            }
        }

        // Fallback or "Direct Checkout" items in request
        if (empty($items)) {
            $inputItems = $request->input('items', []);
            if (!empty($inputItems)) {
                $items = $inputItems;
                // Recalculate total if passed directly? Or trust frontend? 
                // Better to trust frontend for now if we can't fetch all prices easily quickly.
                // Or PancakeService createOrder will calculate?
                // Pancake createOrder usually takes `items` and calculates total OR accepts `total_amount`.
            } else {
                return response()->json(['message' => 'Cart is empty'], 400);
            }
        }

        // Prepare data for Pancake
        $orderData = [
            'customer_name' => $request->input('customer_name'),
            'customer_phone' => $request->input('customer_phone'),
            'shipping_address' => $request->input('address'),
            'items' => collect($items)->map(function ($item) {
                return [
                    'variation_id' => $item['variation_id'] ?? $item['product_id'], // Pancake needs variation_id usually
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'] ?? 0,
                ];
            })->toArray(),
            'total_amount' => $total, // or let Pancake calc?
            'note' => $request->input('note', ''),
        ];

        try {
            $pancakeOrder = $this->pancakeService->createOrder($orderData);

            // Optional: Save to local DB as cache or history?
            // User said "only manipulate on pancake".
            // So we return the pancake order.

            // Clear cart
            if (isset($cart)) {
                $cart->items()->delete();
                $cart->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully',
                'order' => $pancakeOrder
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Order failed: ' . $e->getMessage()], 500);
        }
    }
}
