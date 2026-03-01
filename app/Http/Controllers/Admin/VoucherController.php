<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PancakeService;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index(Request $request)
    {
        try {
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 30);

            $vouchers = $this->pancakeService->getVouchers($page, $limit);

            return response()->json([
                'success' => true,
                'data' => $vouchers->items(),
                'meta' => [
                    'current_page' => $vouchers->currentPage(),
                    'last_page' => $vouchers->lastPage(),
                    'per_page' => $vouchers->perPage(),
                    'total' => $vouchers->total(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching vouchers: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch vouchers'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $voucher = $this->pancakeService->createVoucher($request->all());
            return response()->json(['success' => true, 'data' => $voucher], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating voucher: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to create voucher'], 500);
        }
    }
}
