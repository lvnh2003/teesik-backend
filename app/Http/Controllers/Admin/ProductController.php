<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Pancake\PancakeProductService;
use App\Repositories\LocalProductRepository;

class ProductController extends Controller
{
    public function __construct(
        private LocalProductRepository $products,
        private PancakeProductService $pancakeService
    ) {
    }

    public function index(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 15);
        $search = $request->get('search');
        $categoryId = $request->get('category_id'); // If supported in future

        // Note: status filter not fully implemented in service yet, but can be passed if needed

        $paginator = $this->products->paginate($page, $limit, $search, $categoryId);

        return $this->paginatedResponse($paginator);
    }

    public function show($id)
    {
        $product = $this->products->findByPancakeId($id);
        if (!$product) {
            return $this->errorResponse('Product not found in local catalog', 404);
        }

        return $this->successResponse($product);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $product = $this->pancakeService->createProduct($request->all());
        $this->products->upsertProduct($product);

        return $this->createdResponse($product, 'Product created successfully on Pancake');
    }

    public function update(Request $request, $id)
    {
        $product = $this->pancakeService->updateProduct($id, $request->all());
        $this->products->upsertProduct($product);

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
