<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Pancake\PancakeOrderService;
use App\Services\Pancake\PancakeProductService;
use Carbon\Carbon;

class OrderController extends Controller
{
    protected $voucherService;

    public function __construct(
        PancakeOrderService $pancakeService, 
        PancakeProductService $productService,
        \App\Services\VoucherService $voucherService
    ) {
        $this->pancakeService = $pancakeService;
        $this->productService = $productService;
        $this->voucherService = $voucherService;
    }

    public function checkout(Request $request)
    {


        try {
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
            $cart = null;

            if ($user) {
                $cart = \App\Models\Cart::where('user_id', $user->id)->with('items')->first();
            }

            if (!$cart && $cartId) {
                $cart = \App\Models\Cart::where('cart_id', $cartId)->with('items')->first();
            }

            if ($cart && $cart->items->isNotEmpty()) {
                $items = $cart->items->map(function ($item) {
                    return [
                        'product_id' => $item->product_id,
                        'variation_id' => $item->product_variant_id ?: $item->product_id,
                        'quantity' => (int) ($item->quantity ?? 1),
                        'price' => (float) ($item->price ?? 0),
                        'name' => $item->name ?? 'Product',
                    ];
                })->toArray();
            }

            // Fallback: items in request body if cart is empty
            if (empty($items)) {
                $inputItems = $request->input('items', []);
                if (!empty($inputItems) && is_array($inputItems)) {
                    foreach ($inputItems as $inputItem) {
                        $productId = data_get($inputItem, 'product_id') ?? data_get($inputItem, 'id');
                        if (!$productId) continue;

                        $variationId = data_get($inputItem, 'variation_id') ?? data_get($inputItem, 'variant_id') ?? $productId;
                        
                        $items[] = [
                            'product_id' => $productId,
                            'variation_id' => $variationId,
                            'quantity' => (int) data_get($inputItem, 'quantity', 1),
                            'price' => (float) data_get($inputItem, 'price', 0),
                            'name' => data_get($inputItem, 'name', 'Product'),
                        ];
                    }
                }
            }

            if (empty($items)) {
                return $this->errorResponse('Giỏ hàng trống hoặc thông tin sản phẩm không hợp lệ', 400);
            }

            $subtotal = collect($items)->sum(fn($item) => $item['price'] * $item['quantity']);

            // --- VOUCHER VALIDATION ---
            $voucherCode = $request->input('voucher_code') ?? $request->input('voucherCode') ?? $request->input('voucher');
            $discountAmount = 0;

            if ($voucherCode) {
                try {
                    $result = $this->voucherService->validateVoucher($voucherCode, $subtotal);
                    $discountAmount = $result['discount'];
                    $voucherCode = $result['code']; // Use the normalized code from service
                    // Fallback to frontend discount if validation fails but code was provided
                    $discountAmount = (float) ($request->input('discount_amount') ?? $request->input('discountAmount') ?? 0);
                }
            }

            // Guard against discount larger than total
            $discountAmount = round($discountAmount);
            if ($discountAmount > $subtotal) {
                $discountAmount = $subtotal;
            }

            // Use frontend discount if backend validation failed but voucher exists
            if ($discountAmount == 0 && $voucherCode) {
                $discountAmount = (float) ($request->input('discount_amount') ?? $request->input('discountAmount') ?? 0);
            }

            $shippingFee = (float) $request->input('shipping_fee', 0);
            $paymentMethod = strtoupper($request->input('payment_method', 'COD'));

            // Prepare data for Pancake
            $orderData = [
                'customer_name' => $request->input('customer_name'),
                'customer_email' => $request->input('customer_email') ?: ($user?->email ?? ''),
                'customer_phone' => $request->input('customer_phone'),
                'shipping_address' => $request->input('address'),
                'items' => $items,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'discount_amount' => $discountAmount,
                'payment_method' => $paymentMethod,
                'note' => "PTTT: {$paymentMethod}. " . ($voucherCode ? "Sử dụng ưu đãi: {$voucherCode}. Giảm: " . number_format($discountAmount, 0, ',', '.') . "đ. " : "") . $request->input('note', ''),
            ];

            $pancakeOrder = $this->pancakeService->createOrder($orderData);

            // Clear cart from database
            if ($cart) {
                $cart->items()->delete();
                $cart->delete();
            }
            
            // If user is logged in, double check and clear any other carts
            if ($user) {
                \App\Models\Cart::where('user_id', $user->id)->delete();
            }

            // Clear cache
            \Illuminate\Support\Facades\Cache::forget('pancake_products_master_v1');

            return $this->createdResponse($pancakeOrder, 'Order placed successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Checkout failed: ' . $e->getMessage(), 500);
        }
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
