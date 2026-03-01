<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PancakeService;
use Illuminate\Http\Request;

class PromotionController extends Controller
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

            $promotions = $this->pancakeService->getPromotions($page, $limit);

            return response()->json([
                'success' => true,
                'data' => $promotions->items(),
                'meta' => [
                    'current_page' => $promotions->currentPage(),
                    'last_page' => $promotions->lastPage(),
                    'per_page' => $promotions->perPage(),
                    'total' => $promotions->total(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching promotions: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch promotions'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $promotion = $this->pancakeService->createPromotion($request->all());
            return response()->json(['success' => true, 'data' => $promotion], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating promotion: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to create promotion'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $promotion = $this->pancakeService->updatePromotion($id, $request->all());
            return response()->json(['success' => true, 'data' => $promotion]);
        } catch (\Exception $e) {
            \Log::error('Error updating promotion: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update promotion'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $this->pancakeService->deletePromotion($id);
            return response()->json(['success' => true, 'message' => 'Promotion deleted successfully']);
        } catch (\Exception $e) {
            \Log::error('Error deleting promotion: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete promotion'], 500);
        }
    }
}
