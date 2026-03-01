<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PancakeService;
use Illuminate\Http\Request;

class StatisticController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function sales(Request $request)
    {
        try {
            // Default to last 30 days
            $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
            $endDate = $request->input('end_date', now()->toDateString());

            $data = $this->pancakeService->getSalesAnalytics($startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching sales analytics: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch sales analytics'], 500);
        }
    }

    public function inventory(Request $request)
    {
        try {
            $data = $this->pancakeService->getInventoryAnalytics();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching inventory analytics: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch inventory analytics'], 500);
        }
    }
}
