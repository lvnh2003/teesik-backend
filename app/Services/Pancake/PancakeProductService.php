<?php

namespace App\Services\Pancake;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Pagination\LengthAwarePaginator;

class PancakeProductService extends PancakeClient
{
    public function getProducts($page = 1, $limit = 8, $search = null, $category_id = null)
    {
        $cacheKey = "pancake_products_master_v1";
        $rawData = Cache::remember($cacheKey, 60, function () {
            $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/products", [
                'api_key' => $this->apiKey,
                'page_size' => 1000,
            ]);

            if ($response->failed()) {
                \Log::error('Pancake API error: ' . $response->body());
                return [];
            }

            $data = $response->json();
            return $data['data'] ?? [];
        });

        $allProducts = collect($rawData);

        // Filter and Map
        $filtered = $allProducts;
        
        if ($search) {
            $searchTerm = strtolower($search);
            $filtered = $filtered->filter(function ($item) use ($searchTerm) {
                $name = strtolower($item['name'] ?? '');
                $sku = strtolower($item['display_id'] ?? ($item['barcode'] ?? ''));
                return str_contains($name, $searchTerm) || str_contains($sku, $searchTerm);
            });
        }

        if ($category_id) {
            $filtered = $filtered->filter(function ($item) use ($category_id) {
                $categories = $item['categories'] ?? [];
                return collect($categories)->contains('id', $category_id);
            });
        }

        // Map to our format, ensure uniqueness by ID
        $mapped = $filtered->map(function ($item) use ($category_id) {
            return $this->mapProduct($item, $category_id);
        })->unique('id')->values();

        // Paginate
        $total = $mapped->count();
        $offset = ($page - 1) * $limit;
        $items = $mapped->slice($offset, $limit)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $limit,
            $page,
            [
                'path' => request()->url(), 
                'query' => request()->query(),
                'debug' => [
                    'raw_count' => count($rawData),
                    'mapped_count' => $mapped->count(),
                    'filtered_count' => $filtered->count(),
                ]
            ]
        );
    }

    public function getProduct($id, $refresh = false)
    {
        $cacheKey = 'pancake_product_' . $id;
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        // Cache the product for 60 seconds
        return Cache::remember($cacheKey, 60, function () use ($id) {
            $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/products/{$id}", [
                'api_key' => $this->apiKey
            ]);

            if ($response->failed()) {
                throw new \Exception('Failed to fetch product from Pancake: ' . $response->body());
            }

            $data = $response->json();
            $productData = $data['data'] ?? $data;

            // Fetch variations explicitly
            try {
                $variations = $this->getVariations($id);
                if (!empty($variations)) {
                    $productData['variations'] = $variations;
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to fetch variations for product ' . $id . ': ' . $e->getMessage());
            }

            return $this->mapProduct($productData);
        });
    }

    public function getVariations($productId)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/products/variations", [
            'api_key' => $this->apiKey,
            'product_id' => $productId,
        ]);

        if ($response->failed()) {
            return [];
        }

        $data = $response->json();

        // The Pancake API might ignore the product_id query param and return all variations.
        // We must filter them manually.
        $allVariations = $data['data'] ?? [];
        return array_values(array_filter($allVariations, function ($var) use ($productId) {
            return isset($var['product_id']) && $var['product_id'] === $productId;
        }));
    }

    public function createProduct($data)
    {
        $payload = $this->mapProductPayload($data);

        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/products?api_key={$this->apiKey}", $payload);

        \Log::info('Pancake Create Product Response:', ['status' => $response->status(), 'body' => $response->json()]);

        if ($response->failed()) {
            throw new \Exception('Failed to create product on Pancake: ' . $response->body());
        }

        $responseData = $response->json();
        return $this->mapProduct($responseData['data'] ?? $responseData);
    }

    public function updateProduct($id, $data)
    {
        $payload = $this->mapProductPayload($data);

        $response = Http::put("{$this->baseUrl}/shops/{$this->shopId}/products/{$id}?api_key={$this->apiKey}", $payload);

        if ($response->failed()) {
            throw new \Exception('Failed to update product on Pancake: ' . $response->body());
        }

        $responseData = $response->json();
        return $this->mapProduct($responseData['data'] ?? $responseData);
    }

    public function getCategories()
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/categories", [
            'api_key' => $this->apiKey
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch categories from Pancake: ' . $response->body());
        }

        $data = $response->json();

        return collect($data['data'] ?? [])->map(function ($item) {
            return [
                'id' => $item['id'],
                'name' => $item['text'] ?? 'Unknown',
                'slug' => \Illuminate\Support\Str::slug($item['text'] ?? ''),
            ];
        });
    }

    public function createCategory($name)
    {
        $payload = [
            'name' => $name,
            'text' => $name,
        ];

        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/categories?api_key={$this->apiKey}", $payload);

        if ($response->failed()) {
            throw new \Exception('Failed to create category on Pancake: ' . $response->body());
        }

        $data = $response->json();
        $item = $data['data'] ?? $data;

        return [
            'id' => $item['id'],
            'name' => $item['text'] ?? ($item['name'] ?? $name),
            'slug' => \Illuminate\Support\Str::slug($item['text'] ?? ($item['name'] ?? $name)),
        ];
    }

    protected function mapProduct($pancakeProduct, $targetCategoryId = null)
    {
        $productBase = $pancakeProduct['product'] ?? $pancakeProduct;

        $isParent = isset($productBase['variations']) && is_array($productBase['variations']);
        $firstVar = $isParent && !empty($productBase['variations']) ? $productBase['variations'][0] : null;

        $name = $productBase['name'] ?? 'Unknown Product';
        $fields = $pancakeProduct['fields'] ?? [];
        if (!empty($fields)) {
            $variationParts = collect($fields)->map(function ($f) {
                return $f['value'] ?? '';
            })->filter()->implode(', ');

            if (!empty($variationParts)) {
                $name .= " ($variationParts)";
            }
        }

        $price = (int) ($pancakeProduct['retail_price'] ?? ($pancakeProduct['price'] ?? 0));
        if ($price === 0 && $firstVar) {
            $price = (int) ($firstVar['retail_price'] ?? ($firstVar['price'] ?? 0));
        }
        
        $originalPrice = (int) ($pancakeProduct['original_price'] ?? 0);
        if ($originalPrice === 0 && $firstVar) {
            $originalPrice = (int) ($firstVar['original_price'] ?? 0);
        }

        $images = $pancakeProduct['images'] ?? [];
        if (empty($images) && $firstVar) {
            $images = $firstVar['images'] ?? [];
        }

        // Transform images to expected structure if they are strings
        $images = collect($images)->map(function ($img) {
            return is_string($img) ? ['image_path' => $img, 'id' => null] : $img;
        })->toArray();

        $categories = $productBase['categories'] ?? [];
        
        // Find the matching category if a filter is applied, otherwise use the first one
        $matchingCategory = null;
        if ($targetCategoryId) {
            $matchingCategory = collect($categories)->first(fn($c) => (string)$c['id'] === (string)$targetCategoryId);
        }
        
        $primaryCategory = $matchingCategory ?? ($categories[0] ?? null);
        
        $category = $primaryCategory ? [
            'id' => $primaryCategory['id'],
            'name' => $primaryCategory['name'] ?? 'Unknown',
            'slug' => \Illuminate\Support\Str::slug($primaryCategory['name'] ?? ''),
        ] : null;

        return [
            'id' => $productBase['id'] ?? null,
            'name' => $name,
            'sku' => $productBase['display_id'] ?? ($productBase['barcode'] ?? ''),
            'price' => $price,
            'category_id' => $category['id'] ?? null,
            'category_ids' => collect($categories)->pluck('id')->toArray(),
            'category' => $category,
            'original_price' => $originalPrice,
            'custom_id' => $productBase['custom_id'] ?? null,
            'tags' => $productBase['tags'] ?? [],
            'note' => $productBase['note_product'] ?? '',
            'is_sell_negative' => $productBase['is_sell_negative'] ?? false,
            'hide_config_product' => $productBase['hide_config_product'] ?? false,
            'created_at' => $productBase['inserted_at'] ?? null,
            'updated_at' => null,
            'variations' => $isParent ? collect($productBase['variations'])->map(function ($variant) {
                // Map fields to attributes key-value pair
                $attributes = [];
                if (isset($variant['fields']) && is_array($variant['fields'])) {
                    foreach ($variant['fields'] as $field) {
                        if (isset($field['name']) && isset($field['value'])) {
                            $attributes[$field['name']] = $field['value'];
                        }
                    }
                }

                // Transform variation images
                $varImages = $variant['images'] ?? [];
                $varImages = collect($varImages)->map(function ($img) {
                    return is_string($img) ? ['image_path' => $img, 'id' => null] : $img;
                })->toArray();

                return [
                    'id' => $variant['id'] ?? null,
                    'sku' => $variant['display_id'] ?? ($variant['barcode'] ?? ''),
                    'price' => $variant['retail_price'] ?? ($variant['price'] ?? 0),
                    'original_price' => $variant['original_price'] ?? 0,
                    'stock_quantity' => $variant['remain_quantity'] ?? 0,
                    'weight' => $variant['weight'] ?? 0,
                    'attributes' => $attributes,
                    'images' => $varImages,
                    'image' => null, // Placeholder for frontend handling
                    'imagePreviewUrl' => '', // Placeholder for frontend handling
                    'product_id' => $variant['product_id'] ?? null,
                    'isDelete' => false, // Default for frontend
                ];
            })->toArray() : [],
        ];
    }

    protected function mapVariationsPayload($variationsData)
    {
        $variationsPayload = [];
        if (!is_array($variationsData))
            return $variationsPayload;

        // Resolve warehouse ID ONCE before the loop to avoid N+1 API calls
        $warehouseId = config('pancake.warehouse_id');
        if (!$warehouseId) {
            $warehouses = $this->getWarehouses();
            if (!empty($warehouses)) {
                $warehouseId = $warehouses[0]['id'];
            }
        }

        foreach ($variationsData as $variant) {
            $varPayload = [
                'id' => $variant['id'] ?? null,
                'retail_price' => (int) ($variant['price'] ?? 0),
                'price_at_counter' => (int) ($variant['price'] ?? 0),
                'original_price' => (int) ($variant['original_price'] ?? 0),
                'total_purchase_price' => (int) ($variant['original_price'] ?? 0),
                'last_imported_price' => (int) ($variant['original_price'] ?? 0),
                'barcode' => $variant['sku'] ?? null,
                'display_id' => $variant['sku'] ?? null,
                'weight' => (int) ($variant['weight'] ?? 0),
                'is_hidden' => isset($variant['isDelete']) && $variant['isDelete'] == 'true' ? true : false,
            ];

            if (isset($varPayload['id']) && str_starts_with($varPayload['id'], 'new-')) {
                unset($varPayload['id']);
            }

            if (isset($variant['attributes']) && is_array($variant['attributes'])) {
                $fields = [];
                foreach ($variant['attributes'] as $attr) {
                    if (isset($attr['name']) && isset($attr['value'])) {
                        $fields[] = [
                            'name' => $attr['name'],
                            'value' => $attr['value']
                        ];
                    }
                }
                $varPayload['fields'] = $fields;
            }

            if (isset($variant['images']) && is_array($variant['images'])) {
                $varPayload['images'] = $variant['images'];
            }

            if ($warehouseId && isset($variant['stock_quantity'])) {
                $varPayload['variations_warehouses'] = [
                    [
                        'warehouse_id' => $warehouseId,
                        'remain_quantity' => (int) $variant['stock_quantity']
                    ]
                ];
            } else if (isset($variant['stock_quantity'])) {
                $varPayload['remain_quantity'] = (int) $variant['stock_quantity'];
            }

            $variationsPayload[] = $varPayload;
        }
        return $variationsPayload;
    }

    protected function mapProductPayload($data)
    {
        $productPayload = [
            'name' => $data['name'] ?? null,
            'note_product' => $data['note'] ?? ($data['description'] ?? null),
            'category_ids' => isset($data['category_id']) ? [(int) $data['category_id']] : [],
            'is_published' => isset($data['is_published']) ? (bool) $data['is_published'] : true,
            'is_featured' => false,
            'is_new' => false,
            'custom_id' => $data['custom_id'] ?? null,
            'tags' => isset($data['tags']) && is_array($data['tags']) ? $data['tags'] : [],
            'is_sell_negative' => isset($data['is_sell_negative']) ? filter_var($data['is_sell_negative'], FILTER_VALIDATE_BOOLEAN) : false,
            'hide_config_product' => isset($data['hide_config_product']) ? filter_var($data['hide_config_product'], FILTER_VALIDATE_BOOLEAN) : false,
        ];

        if (isset($data['product_attributes']) && is_array($data['product_attributes'])) {
            $productPayload['product_attributes'] = array_map(function ($attr) {
                return [
                    'name' => $attr['name'],
                    'values' => $attr['values']
                ];
            }, $data['product_attributes']);
        }

        $variationsPayload = $this->mapVariationsPayload($data['variations'] ?? null);
        if (!empty($variationsPayload)) {
            $productPayload['variations'] = $variationsPayload;
        }

        return [
            'product' => array_filter($productPayload, function ($v) {
                return !is_null($v);
            })
        ];
    }

    protected function getImageUrl($images)
    {
        if (empty($images)) {
            return null;
        }
        $firstImage = $images[0];
        if (is_array($firstImage) && isset($firstImage['image_path'])) {
            return $firstImage['image_path'];
        } elseif (is_string($firstImage)) {
            return $firstImage;
        }
        return null;
    }

    protected function getMainImage($images, $variations = [])
    {
        // Try variation images first if variations exist
        if (!empty($variations) && !empty($variations[0]['images'])) {
            $varImg = $variations[0]['images'][0];
            if (is_array($varImg) && isset($varImg['image_path'])) {
                return $varImg['image_path'];
            } elseif (is_string($varImg)) {
                return $varImg;
            }
        }
        return $this->getImageUrl($images);
    }

    /**
     * Called by mapVariationsPayload which needs getWarehouses as fallback.
     * Delegate to PancakeWarehouseService to avoid coupling.
     */
    public function getWarehouses()
    {
        return app(\App\Services\Pancake\PancakeWarehouseService::class)->getWarehouses();
    }
}
