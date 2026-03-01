<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PancakeService;
use Illuminate\Http\Request;

class TransactionController extends Controller
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

            $transactions = $this->pancakeService->getTransactions($page, $limit);

            return response()->json([
                'success' => true,
                'data' => $transactions->items(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching transactions: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch transactions'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $transaction = $this->pancakeService->createTransaction($request->all());
            return response()->json(['success' => true, 'data' => $transaction], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating transaction: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to create transaction'], 500);
        }
    }
}
