<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Repositories\LocalOrderRepository;
use App\Repositories\LocalProductRepository;
use App\Services\Shipping\GhnShippingService;

class OrderController extends Controller
{
    protected $orders;
    protected $productService;
    protected $shippingService;
    protected $voucherService;

    public function __construct(
        LocalOrderRepository $orders,
        LocalProductRepository $productService,
        GhnShippingService $shippingService,
        \App\Services\VoucherService $voucherService
    ) {
        $this->orders = $orders;
        $this->productService = $productService;
        $this->shippingService = $shippingService;
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
                'payment_method' => 'nullable|string|in:cod,qr,momo,card,COD,QR,MOMO,CARD',
                'selected_address_id' => 'nullable|integer',
                'district_id' => 'nullable|integer',
                'ward_code' => 'nullable|string',
                'items' => 'nullable|array',
                'items.*.product_id' => 'nullable',
                'items.*.id' => 'nullable',
                'items.*.variation_id' => 'nullable',
                'items.*.variant_id' => 'nullable',
                'items.*.quantity' => 'required_with:items|integer|min:1|max:99',
            ]);

            $cartId = $request->header('X-Cart-ID');
            $user = $request->bearerToken() ? $request->user('api') : null;

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
                    $productId = $item->product_id;
                    $variationId = $item->product_variant_id;

                    return $this->productService->resolveOrderItem(
                        $productId,
                        $variationId,
                        $item->quantity ?? 1
                    );
                })->toArray();
            }

            // Fallback: items in request body if cart is empty
            if (empty($items)) {
                $inputItems = $request->input('items', []);
                if (!empty($inputItems) && is_array($inputItems)) {
                    foreach ($inputItems as $inputItem) {
                        $productId = data_get($inputItem, 'product_id') ?? data_get($inputItem, 'id');
                        if (!$productId) continue;

                        $variationId = data_get($inputItem, 'variation_id') ?? data_get($inputItem, 'variant_id');

                        $items[] = $this->productService->resolveOrderItem(
                            $productId,
                            $variationId,
                            data_get($inputItem, 'quantity', 1)
                        );
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
                } catch (\Exception $e) {
                    return $this->errorResponse($e->getMessage(), 400);
                }
            }

            // Guard against discount larger than total
            $discountAmount = round($discountAmount);
            if ($discountAmount > $subtotal) {
                $discountAmount = $subtotal;
            }

            $shippingFee = $this->resolveShippingFee($request, $user, $subtotal);
            $paymentMethod = strtoupper($request->input('payment_method', 'COD'));

            // Prepare normalized order data for the local database.
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

            $order = $this->orders->createCheckoutOrder($orderData, $user);

            // Clear cart from database
            if ($cart) {
                $cart->items()->delete();
                $cart->delete();
            }
            
            // If user is logged in, double check and clear any other carts
            if ($user) {
                \App\Models\Cart::where('user_id', $user->id)->delete();
            }

            return $this->createdResponse($order, 'Order placed successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse('Checkout failed: ' . $e->getMessage(), 500);
        }
    }

    private function resolveShippingFee(Request $request, $user, float $subtotal): float
    {
        if ($subtotal > 1000000) {
            return 0;
        }

        $districtId = null;
        $wardCode = null;

        if ($user && $request->filled('selected_address_id')) {
            $address = \App\Models\UserAddress::where('user_id', $user->id)
                ->where('id', $request->input('selected_address_id'))
                ->first();

            if (!$address) {
                throw new \InvalidArgumentException('Địa chỉ giao hàng không hợp lệ.');
            }

            $districtId = $address->district_id;
            $wardCode = $address->ward_code;
        } else {
            $districtId = $request->input('district_id');
            $wardCode = $request->input('ward_code');
        }

        if (!$districtId || !$wardCode) {
            throw new \InvalidArgumentException('Thiếu thông tin khu vực giao hàng.');
        }

        return (float) $this->shippingService->calculateFee($districtId, $wardCode, 300, $subtotal);
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

        $paginator = $this->orders->paginateForUser($user, $page, $limit, $search, $status);

        return $this->paginatedResponse($paginator);
    }
}
