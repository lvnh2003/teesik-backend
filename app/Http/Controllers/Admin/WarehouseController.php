<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Pancake\PancakeWarehouseService;

class WarehouseController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeWarehouseService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index()
    {
        $warehouses = $this->pancakeService->getWarehouses();

        return $this->successResponse($warehouses);
    }

    public function history(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 15);
        $warehouseId = $request->get('warehouse_id');

        $paginator = $this->pancakeService->getInventoryHistories($page, $limit, $warehouseId);

        return $this->paginatedResponse($paginator);
    }
}
