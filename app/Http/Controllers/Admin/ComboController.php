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

    public function store(Request $request)
    {
        try {
            $combo = $this->pancakeService->createCombo($request->all());
            return response()->json(['success' => true, 'data' => $combo], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating combo: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to create combo'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $combo = $this->pancakeService->updateCombo($id, $request->all());
            return response()->json(['success' => true, 'data' => $combo]);
        } catch (\Exception $e) {
            \Log::error('Error updating combo: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update combo'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $this->pancakeService->deleteCombo($id);
            return response()->json(['success' => true, 'message' => 'Combo deleted successfully']);
        } catch (\Exception $e) {
            \Log::error('Error deleting combo: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete combo'], 500);
        }
    }
}
