<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Pancake\PancakeProductService;
use App\Repositories\LocalProductRepository;

class CategoryController extends Controller
{
    public function __construct(
        private LocalProductRepository $products,
        private PancakeProductService $pancakeService
    ) {
    }

    public function index()
    {
        $categories = $this->products->categories();
        return $this->successResponse($categories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $category = $this->pancakeService->createCategory($request->name);
        $this->products->upsertCategory($category);

        return $this->createdResponse($category, 'Category created successfully on Pancake');
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
