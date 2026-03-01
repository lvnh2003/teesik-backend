<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PancakeService;
use Illuminate\Http\Request;

class PurchaseController extends Controller
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

            $purchases = $this->pancakeService->getPurchases($page, $limit);

            return response()->json([
                'success' => true,
                'data' => $purchases->items(),
                'meta' => [
                    'current_page' => $purchases->currentPage(),
                    'last_page' => $purchases->lastPage(),
                    'per_page' => $purchases->perPage(),
                    'total' => $purchases->total(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching purchases: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch purchases'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $purchase = $this->pancakeService->createPurchase($request->all());
            return response()->json(['success' => true, 'data' => $purchase], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating purchase: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to create purchase'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $purchase = $this->pancakeService->updatePurchase($id, $request->all());
            return response()->json(['success' => true, 'data' => $purchase]);
        } catch (\Exception $e) {
            \Log::error('Error updating purchase: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update purchase'], 500);
        }
    }
}
