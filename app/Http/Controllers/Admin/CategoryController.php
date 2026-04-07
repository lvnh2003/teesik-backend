<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Pancake\PancakeProductService;

class CategoryController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeProductService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index()
    {
        $categories = $this->pancakeService->getCategories();
        return $this->successResponse($categories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $category = $this->pancakeService->createCategory($request->name);

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
