<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PancakeService;
use Illuminate\Http\Request;

class ComboController extends Controller
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

            $combos = $this->pancakeService->getCombos($page, $limit);

            return response()->json([
                'success' => true,
                'data' => $combos->items(),
                'meta' => [
                    'current_page' => $combos->currentPage(),
                    'last_page' => $combos->lastPage(),
                    'per_page' => $combos->perPage(),
                    'total' => $combos->total(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching combos: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to fetch combos'], 500);
        }
    }

}
