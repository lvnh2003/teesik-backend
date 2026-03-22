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

}
