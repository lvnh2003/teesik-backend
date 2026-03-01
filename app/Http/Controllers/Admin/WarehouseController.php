<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PancakeService;

class WarehouseController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index()
    {
        try {
            $warehouses = $this->pancakeService->getWarehouses();

            return response()->json([
                'success' => true,
                'data' => $warehouses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error loading warehouses: ' . $e->getMessage()
            ], 500);
        }
    }

    public function history(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 15);
            $warehouseId = $request->get('warehouse_id');

            $paginator = $this->pancakeService->getInventoryHistories($page, $limit, $warehouseId);

            return response()->json([
                'success' => true,
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error loading inventory history: ' . $e->getMessage()
            ], 500);
        }
    }
}
