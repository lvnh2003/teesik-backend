<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PancakeService;
use Illuminate\Http\Request;

class CustomerController extends Controller
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
            $search = $request->input('search');

            $customers = $this->pancakeService->getCustomers($page, $limit, $search);

            return response()->json([
                'success' => true,
                'data' => $customers->items(),
                'meta' => [
                    'current_page' => $customers->currentPage(),
                    'last_page' => $customers->lastPage(),
                    'per_page' => $customers->perPage(),
                    'total' => $customers->total(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching customers: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch customers'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $customer = $this->pancakeService->createCustomer($request->all());
            return response()->json(['success' => true, 'data' => $customer], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating customer: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to create customer'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $customer = $this->pancakeService->updateCustomer($id, $request->all());
            return response()->json(['success' => true, 'data' => $customer]);
        } catch (\Exception $e) {
            \Log::error('Error updating customer: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update customer'], 500);
        }
    }
}
