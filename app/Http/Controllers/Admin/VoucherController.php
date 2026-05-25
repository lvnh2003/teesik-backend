<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\LocalVoucherRepository;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function __construct(private LocalVoucherRepository $vouchers)
    {
    }

    public function index(Request $request)
    {
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 30);

        $vouchers = $this->vouchers->paginate($page, $limit);

        return $this->paginatedResponse($vouchers);
    }

}
