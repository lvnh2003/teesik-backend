<?php

namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Repositories\LocalOrderRepository;

class OrderController extends Controller
{
    public function __construct(private LocalOrderRepository $orders)
    {
    }

    public function index(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 15);
        $search = $request->get('search');
        $status = $request->get('status');

        $paginator = $this->orders->paginate($page, $limit, $search, $status);

        return $this->paginatedResponse($paginator);
    }

    public function show($id)
    {
        try {
            $order = $this->orders->find($id);
            if (!$order) {
                return $this->errorResponse('Order not found', 404);
            }
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

        return response()->json([
            'success' => false,
            'message' => 'Create orders through checkout or sync from Pancake.'
        ], 501);
    }

    public function update(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Order updates should be handled by local order workflow.'
        ], 501);
    }
}
