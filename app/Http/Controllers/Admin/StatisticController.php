<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pancake\PancakeAnalyticsService;
use Illuminate\Http\Request;

class StatisticController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeAnalyticsService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function sales(Request $request)
    {
        // Default to last 30 days
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());

        $data = $this->pancakeService->getSalesAnalytics($startDate, $endDate);

        return $this->successResponse($data);
    }

    public function inventory(Request $request)
    {
        $data = $this->pancakeService->getInventoryAnalytics();

        return $this->successResponse($data);
    }
}
