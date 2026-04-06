<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
            'customer_email' => 'nullable|email',
            'address' => 'required|string',
            'customer_phone' => 'required|string',
        ]);

        $cartId = $request->header('X-Cart-ID');

        $user = $request->user('api');

        $items = [];
        $total = 0;
        $cart = null;

        if ($user) {
            $cart = \App\Models\Cart::where('user_id', $user->id)->with('items')->first();
        }
        
        if (!$cart && $cartId) {
            $cart = \App\Models\Cart::where('cart_id', $cartId)->with('items')->first();
        }

        if ($cart) {
            $items = $cart->items->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'variation_id' => $item->product_variant_id, // We specifically ensured in CartController this is variation ID
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'name' => $item->name,
                ];
            })->toArray();

            $total = $cart->items->sum(function ($item) {
                return $item->price * $item->quantity;
            });
        }

        // Fallback: items in request body — re-validate prices from Pancake
        if (empty($items)) {
            $inputItems = $request->input('items', []);
            if (!empty($inputItems)) {
                $items = [];
                foreach ($inputItems as $inputItem) {
                    try {
                        // Re-fetch price from Pancake to prevent price tampering
                        $pancakeProduct = $this->pancakeService->getProduct($inputItem['product_id']);
                        $price = $pancakeProduct['price'] ?? 0;
                    } catch (\Exception $e) {
                        $price = $inputItem['price'] ?? 0; // Fallback if Pancake unavailable
                    }
                    $items[] = [
                        'product_id' => $inputItem['product_id'],
                        'variation_id' => $inputItem['variation_id'], // Explicit variation ID instead of fallback
                        'quantity' => $inputItem['quantity'] ?? 1,
                        'price' => $price,
                        'name' => $inputItem['name'] ?? '',
                    ];
                }
                $total = collect($items)->sum(fn($item) => $item['price'] * $item['quantity']);
            } else {
                return response()->json(['message' => 'Cart is empty'], 400);
            }
        }

        // Prepare data for Pancake
        $orderData = [
            'customer_name' => $request->input('customer_name'),
            'customer_email' => $request->input('customer_email') ?? ($user?->email ?? ''), // ← pass email
            'customer_phone' => $request->input('customer_phone'),
            'shipping_address' => $request->input('address'),
            'items' => collect($items)->map(function ($item) {
                return [
                    'variation_id' => $item['variation_id'], // Rely strictly on variation_id
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'] ?? 0,
                ];
            })->toArray(),
            'total_amount' => $total,
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

    public function userOrders(Request $request)
    {
        try {
            $user = $request->user('api');
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $page = $request->get('page', 1);
            $limit = $request->get('limit', 15);
            $search = $request->get('search');
            $status = $request->get('status');

            // Search Pancake by user's phone number (more reliable than email
            // since Pancake orders are created with phone as the primary identifier).
            // We fetch orders matching the phone, then verify email ownership locally.
            $pancakeSearchQuery = $user->phone ?? $user->email;
            $paginator = $this->pancakeService->getOrders($page, $limit, $pancakeSearchQuery, $status);

            $items = collect($paginator->items());

            // Filter to only orders belonging to this user (match by phone OR email)
            $userPhone = $user->phone;
            $userEmail = $user->email;
            $items = $items->filter(function ($order) use ($userPhone, $userEmail) {
                $phoneMatch = $userPhone && ($order['customer_phone'] ?? '') === $userPhone;
                $emailMatch = $userEmail && strtolower($order['customer_email'] ?? '') === strtolower($userEmail);
                return $phoneMatch || $emailMatch;
            })->values();

            // Apply additional user search term if provided
            if ($search) {
                $searchLower = strtolower($search);
                $items = $items->filter(function ($order) use ($searchLower) {
                    $idMatch = str_contains(strtolower((string)($order['id'] ?? '')), $searchLower);
                    $nameMatch = str_contains(strtolower($order['customer_name'] ?? ''), $searchLower);
                    $phoneMatch = str_contains(strtolower($order['customer_phone'] ?? ''), $searchLower);
                    return $idMatch || $nameMatch || $phoneMatch;
                })->values();
            }

            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
