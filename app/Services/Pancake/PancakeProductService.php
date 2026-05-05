<?php

namespace App\Services\Pancake;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Pagination\LengthAwarePaginator;

class PancakeProductService extends PancakeClient
{
    public function getProducts($page = 1, $limit = 30, $search = null, $category_id = null)
    {
        $cacheKey = "pancake_products_{$page}_{$limit}_{$search}_{$category_id}";
        return Cache::remember($cacheKey, config('pancake.cache_ttl', 600), function () use ($page, $limit, $search, $category_id) {
            $queryParams = [
                'api_key' => $this->apiKey,
                'page_number' => $page,
                'page_size' => $limit,
            ];

            if ($search) {
                $queryParams['search'] = $search;
            }

            if ($category_id) {
                $queryParams['category_id'] = $category_id;
            }

            $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/products/variations", $queryParams);

            if ($response->failed()) {
                throw new \Exception('Failed to fetch products from Pancake: ' . $response->body());
            }

            $data = $response->json();
            $variations = collect($data['data'] ?? []);

            // Group variations by product_id
            $grouped = $variations->groupBy('product_id');

            $products = $grouped->map(function ($vars, $productId) {
                // Use the first variation to get product-level info (or the 'product' field if available)
                $firstVar = $vars->first();
                $productInfo = $firstVar['product'] ?? [];

                $name = $productInfo['name'] ?? ($firstVar['name'] ?? 'Unknown Product');

                // Aggregate variations
                $mappedVariations = $vars->map(function ($variant) {
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
                        'image' => null,
                        'imagePreviewUrl' => '',
                        'product_id' => $variant['product_id'] ?? null,
                        'isDelete' => false,
                    ];
                })->values()->toArray();

                // Product Images: Try to get from product info, or fallback to first variation's images
                $images = $productInfo['image'] ? [$productInfo['image']] : [];
                if (empty($images)) {
                    $images = $firstVar['images'] ?? [];
                }

                // Normalized images
                $images = collect($images)->map(function ($img) {
                    return is_string($img) ? ['image_path' => $img, 'id' => null] : $img;
                })->toArray();

                $categories = $productInfo['categories'] ?? [];
                $category = isset($categories[0]) ? [
                    'id' => $categories[0]['id'],
                    'name' => $categories[0]['name'] ?? 'Unknown',
                    'slug' => \Illuminate\Support\Str::slug($categories[0]['name'] ?? ''),
                ] : null;

                // Calculate aggregated stock
                $totalStock = $vars->sum('remain_quantity');

                // Price range or first price
                $price = $firstVar['retail_price'] ?? 0;

                return [
                    'id' => $productId,
                    'name' => $name,
                    'sku' => $productInfo['display_id'] ?? ($productInfo['barcode'] ?? ''),
                    'price' => $price,
                    'original_price' => $productInfo['original_price'] ?? 0,
                    'quantity' => $totalStock,
                    'stock_quantity' => $totalStock, // Ensure compatibility
                    'images' => $images,
                    'category_id' => $categories[0]['id'] ?? null,
                    'category' => $category,
                    'custom_id' => $productInfo['custom_id'] ?? null,
                    'tags' => $productInfo['tags'] ?? [],
                    'note' => $productInfo['note_product'] ?? '',
                    'is_sell_negative' => $productInfo['is_sell_negative'] ?? false,
                    'hide_config_product' => $productInfo['hide_config_product'] ?? false,
                    'created_at' => $productInfo['inserted_at'] ?? ($firstVar['inserted_at'] ?? null),
                    'updated_at' => null,
                    'variations' => $mappedVariations,
                ];
            })->values();

            $total = $data['total_entries'] ?? 0;

            return new LengthAwarePaginator(
                $products,
                $total,
                $limit,
                $page,
                ['path' => url('api/admin/products')]
            );
        });
    }

    public function getProduct($id)
    {
        // Cache the product for 5 minutes (300 seconds) to vastly improve "Add to cart" speeds
        return Cache::remember('pancake_product_' . $id, 300, function () use ($id) {
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

    protected function mapProduct($pancakeProduct)
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

        $price = $pancakeProduct['retail_price'] ?? ($pancakeProduct['price'] ?? 0);
        if (empty($price) && $firstVar) {
            $price = $firstVar['retail_price'] ?? ($firstVar['price'] ?? 0);
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
        $category = isset($categories[0]) ? [
            'id' => $categories[0]['id'],
            'name' => $categories[0]['name'] ?? 'Unknown',
            'slug' => \Illuminate\Support\Str::slug($categories[0]['name'] ?? ''),
        ] : null;

        return [
            'id' => $productBase['id'] ?? null,
            'name' => $name,
            'sku' => $productBase['display_id'] ?? ($productBase['barcode'] ?? ''),
            'price' => $price,
            'original_price' => $productBase['original_price'] ?? 0,
            'quantity' => $productBase['remain_quantity'] ?? 0,
            'images' => $images,
            'category_id' => $categories[0]['id'] ?? null,
            'category' => $category,
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
