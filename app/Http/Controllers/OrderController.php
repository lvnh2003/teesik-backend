<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Pancake\PancakeOrderService;
use App\Services\Pancake\PancakeProductService;

class OrderController extends Controller
{
    protected $pancakeService;
    protected $productService;

    public function __construct(PancakeOrderService $pancakeService, PancakeProductService $productService)
    {
        $this->pancakeService = $pancakeService;
        $this->productService = $productService;
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

        // Fallback: items in request body -- re-validate prices from Pancake
        if (empty($items)) {
            $inputItems = $request->input('items', []);
            if (!empty($inputItems)) {
                $items = [];
                foreach ($inputItems as $inputItem) {
                    try {
                        // Re-fetch price from Pancake to prevent price tampering
                        $pancakeProduct = $this->productService->getProduct($inputItem['product_id']);
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
                return $this->errorResponse('Cart is empty', 400);
            }
        }

        // --- VOUCHER VALIDATION ---
        $voucherCode = $request->input('voucher_code');
        $discountAmount = 0;

        if ($voucherCode) {
            try {
                $marketingService = app(\App\Services\Pancake\PancakeMarketingService::class);
                $paginator = $marketingService->getVouchers(1, 100);
                $vouchers = collect($paginator->items());
                
                $voucher = $vouchers->firstWhere('code', strtoupper($voucherCode));
                if (!$voucher) $voucher = $vouchers->firstWhere('name', strtoupper($voucherCode));

                if ($voucher) {
                    $minOrder = $voucher['condition_amount'] ?? ($voucher['min_order_value'] ?? 0);
                    if ($total >= $minOrder) {
                        $isPercent = $voucher['is_use_percent'] ?? ($voucher['promo_code_info']['is_percent'] ?? ($voucher['is_percent'] ?? false));
                        if ($isPercent) {
                            $percent = $voucher['value_discount'] ?? ($voucher['promo_code_info']['discount'] ?? 0);
                            $discountAmount = ($total * $percent) / 100;
                            $maxDiscount = $voucher['max_amount_discount'] ?? ($voucher['promo_code_info']['max_discount_by_percent'] ?? ($voucher['max_discount'] ?? 0));
                            if ($maxDiscount > 0 && $discountAmount > $maxDiscount) {
                                $discountAmount = $maxDiscount;
                            }
                        } else {
                            $discountAmount = $voucher['value_discount'] ?? ($voucher['promo_code_info']['discount'] ?? 0);
                        }
                        
                        if ($discountAmount > $total) $discountAmount = $total;
                    }
                }
            } catch (\Exception $e) {}
        }

        $finalTotal = $total - $discountAmount;
        $paymentMethod = strtoupper($request->input('payment_method', 'COD'));

        // Prepare data for Pancake
        $orderData = [
            'customer_name' => $request->input('customer_name'),
            'customer_email' => $request->input('customer_email') ?? ($user?->email ?? ''),
            'customer_phone' => $request->input('customer_phone'),
            'shipping_address' => $request->input('address'),
            'items' => collect($items)->map(function ($item) {
                return [
                    'variation_id' => $item['variation_id'],
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'] ?? 0,
                ];
            })->toArray(),
            'total_amount' => $finalTotal,
            'note' => "PTTT: {$paymentMethod}. " . ($voucherCode ? "Sử dụng ưu đãi: {$voucherCode}. " : "") . $request->input('note', ''),
        ];

        $pancakeOrder = $this->pancakeService->createOrder($orderData);

        // Optional: Save to local DB as cache or history?
        // User said "only manipulate on pancake".
        // So we return the pancake order.

        // Clear cart
        if (isset($cart)) {
            $cart->items()->delete();
            $cart->delete();
        }

        return $this->createdResponse($pancakeOrder, 'Order placed successfully');
    }

    public function userOrders(Request $request)
    {
        $user = $request->user('api');
        if (!$user) {
            return $this->errorResponse('Unauthorized', 401);
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

        return $this->paginatedResponse($paginator, $items);
    }
}
