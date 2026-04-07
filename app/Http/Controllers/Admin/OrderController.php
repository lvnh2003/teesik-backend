<?php

namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Pancake\PancakeOrderService;

class OrderController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeOrderService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 15);
        $search = $request->get('search');
        $status = $request->get('status');

        $paginator = $this->pancakeService->getOrders($page, $limit, $search, $status);

        return $this->paginatedResponse($paginator);
    }

    public function show($id)
    {
        try {
            $order = $this->pancakeService->getOrder($id);
            return $this->successResponse($order);
        } catch (\Exception $e) {
            return $this->errorResponse('Order not found', 404);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_name' => 'required|string',
            'customer_phone' => 'required|string',
            'items' => 'required|array',
            'items.*.variation_id' => 'required',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $order = $this->pancakeService->createOrder($request->all());

        return $this->createdResponse($order, 'Order created successfully on Pancake');
    }

    public function update(Request $request, $id)
    {
        $order = $this->pancakeService->updateOrder($id, $request->all());

        return $this->successResponse($order, 'Order updated successfully on Pancake');
    }
}
