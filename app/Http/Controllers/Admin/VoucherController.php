<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pancake\PancakeMarketingService;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeMarketingService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 30);

        $vouchers = $this->pancakeService->getVouchers($page, $limit);

        return $this->paginatedResponse($vouchers);
    }

}
