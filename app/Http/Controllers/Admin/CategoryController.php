<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PancakeService;

class CategoryController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index()
    {
        try {
            $categories = $this->pancakeService->getCategories();
            return response()->json(['data' => $categories]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error loading categories: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $category = $this->pancakeService->createCategory($request->name);

            return response()->json([
                'success' => true,
                'data' => $category,
                'message' => 'Category created successfully on Pancake'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating category: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        // Implement if needed via API, currently just list is main requirement
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function update(Request $request, $id)
    {
        return response()->json(['message' => 'Update category via Pancake POS'], 501);
    }

    public function destroy($id)
    {
        return response()->json(['message' => 'Delete category via Pancake POS'], 501);
    }
}