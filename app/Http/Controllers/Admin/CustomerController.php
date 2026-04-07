<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pancake\PancakeCustomerService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeCustomerService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 30);
        $search = $request->input('search');

        $customers = $this->pancakeService->getCustomers($page, $limit, $search);

        return $this->paginatedResponse($customers);
    }

    public function store(Request $request)
    {
        $customer = $this->pancakeService->createCustomer($request->all());
        return $this->createdResponse($customer);
    }

    public function update(Request $request, $id)
    {
        $customer = $this->pancakeService->updateCustomer($id, $request->all());
        return $this->successResponse($customer);
    }
}
