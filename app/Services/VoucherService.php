<?php

namespace App\Services;

use App\Repositories\LocalVoucherRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VoucherService
{
    protected $cacheKey = 'pancake_vouchers_list';
    protected $cacheTTL = 600; // 10 minutes

    public function __construct(private LocalVoucherRepository $vouchers)
    {
    }

    /**
     * Get all vouchers from Pancake with caching.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAllVouchers()
    {
        return $this->vouchers->all();
    }

    /**
     * Get only active and valid vouchers for public display.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getActiveVouchers()
    {
        $vouchers = $this->getAllVouchers();
        $now = Carbon::now();

        return $vouchers->filter(function ($voucher) use ($now) {
            // Must have a code
            if (empty($voucher['code'])) return false;

            // Must be activated
            if (!$voucher['is_activated']) return false;

            // Date range check
            if (!empty($voucher['start_date'])) {
                if ($now->lt(Carbon::parse($voucher['start_date']))) return false;
            }
            if (!empty($voucher['end_date'])) {
                if ($now->gt(Carbon::parse($voucher['end_date']))) return false;
            }

            // Usage limit check
            if ($voucher['usage_limit'] > 0 && $voucher['used_count'] >= $voucher['usage_limit']) return false;

            return true;
        })->values();
    }

    /**
     * Normalize voucher data from Pancake API.
     *
     * @param array $voucher
     * @return array
     */
    public function normalizeVoucher(array $voucher): array
    {
        $code = $voucher['code'] ?? ($voucher['promo_code'] ?? ($voucher['name'] ?? ''));
        $isActivated = $voucher['is_activated'] ?? true;
        $isPercent = $voucher['is_use_percent'] ?? ($voucher['promo_code_info']['is_percent'] ?? ($voucher['is_percent'] ?? false));
        $discountValue = $voucher['value_discount'] ?? ($voucher['discount_value'] ?? ($voucher['promo_code_info']['discount'] ?? 0));
        $maxDiscount = $voucher['max_amount_discount'] ?? ($voucher['max_discount'] ?? ($voucher['promo_code_info']['max_discount_by_percent'] ?? 0));
        $minOrderValue = $voucher['condition_amount'] ?? ($voucher['min_order_value'] ?? ($voucher['promo_code_info']['min_order_amount'] ?? 0));
        $usageLimit = $voucher['usage_limit'] ?? ($voucher['quantity'] ?? 0);
        $usedCount = $voucher['used_count'] ?? ($voucher['used_quantity'] ?? 0);

        return [
            'code' => $code,
            'name' => $voucher['name'] ?? $code,
            'description' => $voucher['description'] ?? '',
            'is_activated' => (bool)$isActivated,
            'start_date' => $voucher['start_date'] ?? null,
            'end_date' => $voucher['end_date'] ?? null,
            'usage_limit' => (int)$usageLimit,
            'used_count' => (int)$usedCount,
            'is_percent' => (bool)$isPercent,
            'discount_value' => (float)$discountValue,
            'max_discount' => (float)$maxDiscount,
            'min_order_value' => (float)$minOrderValue,
            'remaining' => $usageLimit > 0 ? max(0, $usageLimit - $usedCount) : null,
            'raw' => $voucher
        ];
    }

    /**
     * Validate a voucher code against a cart total.
     *
     * @param string $code
     * @param float $cartTotal
     * @return array
     * @throws \Exception
     */
    public function validateVoucher(string $code, float $cartTotal): array
    {
        $code = trim($code);
        $vouchers = $this->getAllVouchers();
        
        // Case-insensitive search
        $rawVoucher = $vouchers->first(function ($item) use ($code) {
            $itemCode = $item['code'] ?? ($item['promo_code'] ?? '');
            $itemName = $item['name'] ?? '';
            return strtolower($itemCode) === strtolower($code) 
                || strtolower($itemName) === strtolower($code);
        });

        if (!$rawVoucher) {
            throw new \Exception('Mã giảm giá không hợp lệ hoặc đã kết thúc.');
        }

        $voucher = $this->normalizeVoucher($rawVoucher);

        // Check if activated
        if (!$voucher['is_activated']) {
            throw new \Exception('Mã giảm giá đã bị khóa.');
        }
        
        // Time range check
        $now = Carbon::now();
        if (!empty($voucher['start_date'])) {
            if ($now->lt(Carbon::parse($voucher['start_date']))) {
                throw new \Exception('Mã giảm giá chưa đến thời gian sử dụng.');
            }
        }
        
        if (!empty($voucher['end_date'])) {
            if ($now->gt(Carbon::parse($voucher['end_date']))) {
                throw new \Exception('Mã giảm giá đã quá hạn.');
            }
        }

        // Usage limit check
        if ($voucher['usage_limit'] > 0 && $voucher['used_count'] >= $voucher['usage_limit']) {
            throw new \Exception('Mã giảm giá đã hết lượt sử dụng.');
        }
        
        // Min order value check
        if ($cartTotal < $voucher['min_order_value']) {
            $formattedMin = number_format($voucher['min_order_value'], 0, ',', '.') . 'đ';
            throw new \Exception("Chưa đạt giá trị đơn hàng tối thiểu {$formattedMin}.");
        }
        
        // Calculate discount
        $discount = 0;
        if ($voucher['is_percent']) {
            $discount = ($cartTotal * $voucher['discount_value']) / 100;
            if ($voucher['max_discount'] > 0 && $discount > $voucher['max_discount']) {
                $discount = $voucher['max_discount'];
            }
        } else {
            $discount = $voucher['discount_value'];
        }

        // Round the discount amount
        $discount = round($discount);

        // Guard against discount larger than total
        if ($discount > $cartTotal) {
            $discount = $cartTotal;
        }

        return [
            'isValid' => true,
            'code' => $voucher['code'],
            'discount' => $discount,
            'is_percent' => $voucher['is_percent'],
            'name' => $voucher['name'],
            'normalized_voucher' => $voucher
        ];
    }

    /**
     * Clear the voucher cache.
     *
     * @return bool
     */
    public function clearCache()
    {
        return Cache::forget($this->cacheKey);
    }
}
