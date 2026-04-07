<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Pancake\PancakeProductService;

class ProductController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeProductService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 15);
        $search = $request->get('search');
        $categoryId = $request->get('category_id'); // If supported in future

        // Note: status filter not fully implemented in service yet, but can be passed if needed

        $paginator = $this->pancakeService->getProducts($page, $limit, $search, $categoryId);

        return $this->paginatedResponse($paginator);
    }

    public function show($id)
    {
        $product = $this->pancakeService->getProduct($id);

        return $this->successResponse($product);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $product = $this->pancakeService->createProduct($request->all());

        return $this->createdResponse($product, 'Product created successfully on Pancake');
    }

    public function update(Request $request, $id)
    {
        $product = $this->pancakeService->updateProduct($id, $request->all());

        return $this->successResponse($product, 'Product updated successfully on Pancake');
    }

    public function destroy($id)
    {
        // Deletion might not be supported via simple API call or might require different permissions.
        // Keeping as 501 for now unless "Delete Product" API is confirmed.
        return response()->json([
            'success' => false,
            'message' => 'Product deletion should be done via Pancake POS.'
        ], 501);
    }
}
