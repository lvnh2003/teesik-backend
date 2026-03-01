<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PancakeService;

class ProductController extends Controller
{
    protected $pancakeService;

    public function __construct(PancakeService $pancakeService)
    {
        $this->pancakeService = $pancakeService;
    }

    public function index(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 12);
            $search = $request->get('search');
            $categoryId = $request->get('category_id');

            // Handle slug category filter if needed, but Pancake uses ID.
            // Frontend should pass ID or we'd need a slug-to-ID lookup (which we can't easily do without caching categories).
            // For now assume frontend passes category_id or we ignore slug filter.

            $paginator = $this->pancakeService->getProducts($page, $limit, $search, $categoryId);

            return response()->json([
                'success' => true,
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching products: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $data = $request->all();

            // Handle variations (including file uploads and attribute parsing)
            if (isset($data['variations']) && is_array($data['variations'])) {
                $data['variations'] = $this->processVariations($request, $data['variations']);
            }

            // Parse global product_attributes
            if (isset($data['product_attributes']) && is_array($data['product_attributes'])) {
                $data['product_attributes'] = $this->parseAttributes($data['product_attributes']);
            }

            $product = $this->pancakeService->createProduct($data);

            return response()->json([
                'success' => true,
                'data' => $product,
                'message' => 'Product created successfully'
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Product Create Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $product = $this->pancakeService->getProduct($id);

            return response()->json([
                'success' => true,
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Product not found', 'error' => $e->getMessage()], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $data = $request->all();

            // Handle variations (including file uploads and attribute parsing)
            if (isset($data['variations']) && is_array($data['variations'])) {
                $data['variations'] = $this->processVariations($request, $data['variations']);
            }

            // Parse global product_attributes
            if (isset($data['product_attributes']) && is_array($data['product_attributes'])) {
                $data['product_attributes'] = $this->parseAttributes($data['product_attributes']);
            }

            $updatedProduct = $this->pancakeService->updateProduct($id, $data);

            return response()->json([
                'success' => true,
                'data' => $updatedProduct,
                'message' => 'Product updated successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Product Update Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process variations: parse attributes and handle image uploads.
     */
    private function processVariations(Request $request, array $variations)
    {
        $formattedVariations = [];

        foreach ($variations as $index => $variant) {
            // Parse attributes
            if (isset($variant['attributes']) && is_array($variant['attributes'])) {
                $variant['attributes'] = $this->parseAttributes($variant['attributes']);
            }

            // Handle image upload
            if ($request->hasFile("variations.$index.image")) {
                $file = $request->file("variations.$index.image");
                $path = $file->store('products', 'public');
                $url = asset("storage/$path");
                $variant['images'] = [$url];
            }

            $formattedVariations[] = $variant;
        }

        return $formattedVariations;
    }

    /**
     * Parse attributes from JSON strings if necessary.
     */
    private function parseAttributes(array $attributes)
    {
        $parsedAttributes = [];
        foreach ($attributes as $attr) {
            if (is_string($attr)) {
                $decoded = json_decode($attr, true);
                if ($decoded) {
                    $parsedAttributes[] = $decoded;
                }
            } else {
                $parsedAttributes[] = $attr;
            }
        }
        return $parsedAttributes;
    }
}
