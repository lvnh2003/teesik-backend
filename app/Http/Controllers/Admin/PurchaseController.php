<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pancake\PancakePurchaseService;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakePurchaseService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 30);

        $purchases = $this->pancakeService->getPurchases($page, $limit);

        return $this->paginatedResponse($purchases);
    }

    public function store(Request $request)
    {
        $purchase = $this->pancakeService->createPurchase($request->all());
        return $this->createdResponse($purchase);
    }

    public function update(Request $request, $id)
    {
        $purchase = $this->pancakeService->updatePurchase($id, $request->all());
        return $this->successResponse($purchase);
    }
}
