<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Pancake\PancakeMarketingService;
use Carbon\Carbon;

class VoucherController extends Controller
{
    protected $marketingService;

    public function __construct(PancakeMarketingService $marketingService)
    {
        $this->marketingService = $marketingService;
    }

    public function validateVoucher(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'cart_total' => 'required|numeric'
        ]);

        $code = trim($request->code);
        $cartTotal = $request->cart_total;

        try {
            // Lấy voucher, fetch limit 100 để đủ danh sách các voucher đang có
            $paginator = $this->marketingService->getVouchers(1, 100);
            $vouchers = collect($paginator->items());
            
            // Search field in Pancake voucher might be 'code' or 'name' based on API structure. Defaults to 'code'.
            $voucher = $vouchers->first(function ($item) use ($code) {
                return strtolower($item['code'] ?? '') === strtolower($code) 
                    || strtolower($item['name'] ?? '') === strtolower($code);
            });

            if (!$voucher) {
                return $this->errorResponse('Mã giảm giá không hợp lệ hoặc đã kết thúc.', 404);
            }

            // Check if active
            if (isset($voucher['is_activated']) && !$voucher['is_activated']) {
                return $this->errorResponse('Mã giảm giá đã bị khóa.', 400);
            }
            
            // Time range check
            // pancake marketing service already format start_date/end_date to YYYY-MM-DDTHH:ii:ss
            $now = Carbon::now();
            if (!empty($voucher['start_date'])) {
                if ($now->lt(Carbon::parse($voucher['start_date']))) {
                    return $this->errorResponse('Mã giảm giá chưa đến thời gian sử dụng.', 400);
                }
            }
            
            if (!empty($voucher['end_date'])) {
                if ($now->gt(Carbon::parse($voucher['end_date']))) {
                    return $this->errorResponse('Mã giảm giá đã quá hạn.', 400);
                }
            }
            
            // Min order value (usually 'condition_amount' or 'min_order_value')
            $minOrder = $voucher['condition_amount'] ?? ($voucher['min_order_value'] ?? 0);
            if ($cartTotal < $minOrder) {
                return $this->errorResponse('Chưa đạt giá trị đơn hàng tối thiểu ' . number_format($minOrder) . 'đ.', 400);
            }
            
            // Calculate discount
            $discount = 0;
            // Pancake returns is_use_percent or promo_code_info.is_percent
            $isPercent = $voucher['is_use_percent'] ?? ($voucher['promo_code_info']['is_percent'] ?? false);
            
            if ($isPercent) {
                $percent = $voucher['value_discount'] ?? ($voucher['promo_code_info']['discount'] ?? 0);
                $discount = ($cartTotal * $percent) / 100;
                $maxDiscount = $voucher['max_amount_discount'] ?? ($voucher['promo_code_info']['max_discount_by_percent'] ?? 0);
                if ($maxDiscount > 0 && $discount > $maxDiscount) {
                    $discount = $maxDiscount;
                }
            } else {
                $discount = $voucher['value_discount'] ?? ($voucher['promo_code_info']['discount'] ?? 0);
            }

            // Guard against discount larger than total
            if ($discount > $cartTotal) {
                $discount = $cartTotal;
            }

            return $this->successResponse([
                'code' => $code,
                'discount' => round($discount),
                'is_percent' => $isPercent,
                'voucher_details' => [
                    'name' => $voucher['name'] ?? $code,
                    'value_discount' => $voucher['value_discount'] ?? ($voucher['promo_code_info']['discount'] ?? 0),
                    'max_discount' => $voucher['max_amount_discount'] ?? ($voucher['promo_code_info']['max_discount_by_percent'] ?? 0)
                ]
            ], 'Áp dụng mã giảm giá thành công.');

        } catch (\Exception $e) {
            return $this->errorResponse('Lỗi hệ thống khi kiểm tra mã giảm giá.', 500);
        }
    }
}
