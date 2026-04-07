<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pancake\PancakeTransactionService;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeTransactionService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 30);

        $transactions = $this->pancakeService->getTransactions($page, $limit);

        return $this->paginatedResponse($transactions);
    }

    public function store(Request $request)
    {
        $transaction = $this->pancakeService->createTransaction($request->all());
        return $this->createdResponse($transaction);
    }
}
