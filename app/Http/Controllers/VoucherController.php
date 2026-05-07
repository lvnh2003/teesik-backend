<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\VoucherService;

class VoucherController extends Controller
{
    protected $voucherService;

    public function __construct(VoucherService $voucherService)
    {
        $this->voucherService = $voucherService;
    }

    /**
     * List all active vouchers for the public.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $vouchers = $this->voucherService->getActiveVouchers();
            
            $formattedVouchers = $vouchers->map(function ($voucher) {
                return [
                    'code' => $voucher['code'],
                    'name' => $voucher['name'],
                    'description' => $voucher['description'],
                    'discount_value' => $voucher['discount_value'],
                    'is_percent' => $voucher['is_percent'],
                    'max_discount' => $voucher['max_discount'],
                    'min_order_value' => $voucher['min_order_value'],
                    'end_date' => $voucher['end_date'],
                    'remaining' => $voucher['remaining'],
                ];
            });

            return $this->successResponse($formattedVouchers);
        } catch (\Exception $e) {
            return $this->errorResponse('Không thể lấy danh sách mã giảm giá.', 500);
        }
    }

    /**
     * Validate a voucher code.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateVoucher(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'cart_total' => 'required|numeric'
        ]);

        try {
            $result = $this->voucherService->validateVoucher($request->code, $request->cart_total);
            $voucher = $result['normalized_voucher'];

            return $this->successResponse([
                'code' => $result['code'],
                'discount' => $result['discount'],
                'is_percent' => $result['is_percent'],
                'voucher_details' => [
                    'name' => $result['name'],
                    'discount_value' => $voucher['discount_value'],
                    'max_discount' => $voucher['max_discount'],
                    'min_order_value' => $voucher['min_order_value']
                ]
            ], 'Áp dụng mã giảm giá thành công.');

        } catch (\Exception $e) {
            $message = $e->getMessage();
            $statusCode = 400;

            if ($message === 'Mã giảm giá không hợp lệ hoặc đã kết thúc.') {
                $statusCode = 404;
            }

            return $this->errorResponse($message, $statusCode);
        }
    }

    /**
     * Refresh voucher cache.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refreshCache()
    {
        try {
            $this->voucherService->clearCache();
            return $this->successResponse(null, 'Làm mới danh sách mã giảm giá thành công.');
        } catch (\Exception $e) {
            return $this->errorResponse('Không thể làm mới danh sách mã giảm giá.', 500);
        }
    }
}
