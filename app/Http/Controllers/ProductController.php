<?php

namespace App\Http\Controllers;

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
        $limit = $request->get('limit', 12);
        $search = $request->get('search');
        $categoryId = $request->get('category_id');

        // Handle slug category filter if needed, but Pancake uses ID.
        // Frontend should pass ID or we'd need a slug-to-ID lookup (which we can't easily do without caching categories).
        // For now assume frontend passes category_id or we ignore slug filter.

        $paginator = $this->pancakeService->getProducts($page, $limit, $search, $categoryId);

        return $this->paginatedResponse($paginator);
    }

    public function store(Request $request)
    {
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

        return $this->createdResponse($product, 'Product created successfully');
    }

    public function show($id)
    {
        try {
            $product = $this->pancakeService->getProduct($id);
            return $this->successResponse($product);
        } catch (\Exception $e) {
            return $this->errorResponse('Product not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
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

        return $this->successResponse($updatedProduct, 'Product updated successfully');
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
