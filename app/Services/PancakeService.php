<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Pagination\LengthAwarePaginator;

class PancakeService
{
    protected $apiKey;
    protected $shopId;
    protected $baseUrl = 'https://pos.pages.fm/api/v1';

    public function __construct()
    {
        $this->apiKey = env('PANCAKE_API_KEY');
        $this->shopId = env('PANCAKE_SHOP_ID');
    }

    public function getOrders($page = 1, $limit = 15, $search = null, $status = null)
    {
        $queryParams = [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit,
        ];

        if ($search) {
            $queryParams['search'] = $search;
        }

        if ($status) {
            $queryParams['filter_status'] = is_array($status) ? $status : [$status];
        }

        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/orders", $queryParams);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch orders from Pancake: ' . $response->body());
        }

        $data = $response->json();

        $orders = collect($data['data'] ?? [])->map(function ($item) {
            return $this->mapOrder($item);
        });

        $total = $data['total_entries'] ?? 0;

        return new LengthAwarePaginator(
            $orders,
            $total,
            $limit,
            $page,
            ['path' => url('api/admin/orders')]
        );
    }

    public function getOrder($id)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/orders/{$id}", [
            'api_key' => $this->apiKey
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch order from Pancake: ' . $response->body());
        }

        $data = $response->json();
        $orderData = $data['data'] ?? $data;

        if (!isset($orderData['id'])) {
            throw new \Exception('API response missing ID. Response: ' . json_encode($data));
        }

        return $this->mapOrder($orderData);
    }

    public function getProducts($page = 1, $limit = 30, $search = null, $category_id = null)
    {
        $queryParams = [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit,
            // 'fields' => 'id,name,images,variations,categories,retail_price,remain_quantity,original_price', // Optional filtering
        ];

        if ($search) {
            $queryParams['search'] = $search;
        }

        // Use /products/variations to get full details including images and proper structure
        // But this returns a list of variations. We need to group them by product_id to form "Products".
        // Pagination here applies to variations, which might be tricky if we want 30 *products*.
        // For now, let's fetch variations and group them. 
        // Note: This might result in fewer than $limit products per page if products have multiple variations.
        // A better approach would be to fetch products, then fetch variations for them, but that's N+1.
        // Or fetch a larger number of variations and group.

        // Let's try fetching from /products/variations and grouping.
        // The user specifically pointed to this endpoint for correct display.

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

            // If product info is missing, we might need to rely on variation info
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

        $total = $data['total_entries'] ?? 0; // This is total variations, not products.
        // We can't easily get total products count without fetching all. 
        // For now, let's use the variation count as a proxy or just pass it ensuring frontend handles pages.
        // Actually, returning total variations count might confuse pagination of products.
        // But since we are paginating variations, maybe it's acceptable? 
        // Let's assume 1 product = 1 variation roughly for pagination purposes, or just return total variations count.

        return new LengthAwarePaginator(
            $products,
            $total,
            $limit,
            $page,
            ['path' => url('api/admin/products')]
        );
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
            // Some APIs might use 'text' or 'category' wrapper.
            // Assuming 'name' at root or trying standard guess.
            // If Pancake uses 'text' for read, maybe 'text' for write?
            // Let's try sending both or check response.
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

    public function getProduct($id)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/products/{$id}", [
            'api_key' => $this->apiKey
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch product from Pancake: ' . $response->body());
        }

        $data = $response->json();
        $productData = $data['data'] ?? $data;

        // Fetch variations if the product has them or if we want to be sure
        // The /products/{id} endpoint might strictly limited variation info.
        // Let's try to fetch variations explicitly.
        try {
            $variations = $this->getVariations($id);
            if (!empty($variations)) {
                $productData['variations'] = $variations;
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to fetch variations for product ' . $id . ': ' . $e->getMessage());
        }

        return $this->mapProduct($productData);
    }

    public function getVariations($productId)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/products/variations", [
            'api_key' => $this->apiKey,
            'product_id' => $productId,
            // 'page_size' => 100 // Fetch all variations?
        ]);

        if ($response->failed()) {
            // throw new \Exception('Failed to fetch variations: ' . $response->body());
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

    public function getWarehouses()
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/warehouses", [
            'api_key' => $this->apiKey
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch warehouses: ' . $response->body());
        }

        $data = $response->json();
        return $data['data'] ?? $data;
    }

    public function getInventoryHistories($page = 1, $limit = 30, $warehouseId = null)
    {
        $queryParams = [
            'api_key' => $this->apiKey,
            'page' => $page,
            'page_size' => $limit,
        ];

        if ($warehouseId) {
            $queryParams['warehouse_id'] = $warehouseId;
        }

        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/inventory_histories", $queryParams);

        if ($response->failed()) {
            throw new \Exception('Failed to fetch inventory history: ' . $response->body());
        }

        $data = $response->json();

        $histories = $data['data'] ?? [];
        $total = $data['total_entries'] ?? 0;

        return new LengthAwarePaginator(
            $histories,
            $total,
            $limit,
            $page,
            ['path' => url('api/admin/inventory-history')]
        );
    }

    private function mapVariationsPayload($variationsData)
    {
        $variationsPayload = [];
        if (!is_array($variationsData))
            return $variationsPayload;

        // Resolve warehouse ID ONCE before the loop to avoid N+1 API calls
        $warehouseId = env('PANCAKE_WAREHOUSE_ID');
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

    private function mapProductPayload($data)
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

    public function createProduct($data)
    {
        $payload = $this->mapProductPayload($data);

        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/products?api_key={$this->apiKey}", $payload);

        \Log::info('Pancake Create Product Response:', ['status' => $response->status(), 'body' => $response->json()]);

        if ($response->failed()) {
            throw new \Exception('Failed to create product on Pancake: ' . $response->body());
        }

        $data = $response->json();
        return $this->mapProduct($data['data'] ?? $data);
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

    public function createOrder($data)
    {
        $payload = [
            'bill_full_name' => $data['customer_name'] ?? 'Guest',
            'bill_phone_number' => $data['customer_phone'] ?? '',
            'bill_address' => $data['shipping_address'] ?? '',
            'bill_email' => $data['customer_email'] ?? '', // ← needed for user order lookup
            'items' => collect($data['items'] ?? [])->map(function ($item) {
                return [
                    'variation_id' => $item['variation_id'] ?? null,
                    'product_id' => $item['product_id'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'price' => $item['price'] ?? 0,
                ];
            })->toArray(),
            'total_amount' => $data['total_amount'] ?? 0,
            'note' => $data['note'] ?? '',
        ];

        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/orders?api_key={$this->apiKey}", $payload);

        if ($response->failed()) {
            throw new \Exception('Failed to create order on Pancake: ' . $response->body());
        }

        $data = $response->json();
        return $this->mapOrder($data['data'] ?? $data);
    }

    public function updateOrder($id, $data)
    {
        $payload = [
            'bill_full_name' => $data['customer_name'] ?? null,
            'note' => $data['note'] ?? null,
        ];

        $payload = array_filter($payload, function ($v) {
            return !is_null($v);
        });

        $response = Http::put("{$this->baseUrl}/shops/{$this->shopId}/orders/{$id}?api_key={$this->apiKey}", $payload);

        if ($response->failed()) {
            throw new \Exception('Failed to update order on Pancake: ' . $response->body());
        }

        $data = $response->json();
        return $this->mapOrder($data['data'] ?? $data);
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

    protected function mapOrder($pancakeOrder)
    {
        $address = $pancakeOrder['shipping_address'] ?? '';
        if (is_array($address)) {
            $parts = [
                $address['address'] ?? '',
                $address['commune_name'] ?? '',
                $address['district_name'] ?? '',
                $address['province_name'] ?? ''
            ];
            $address = $address['full_address'] ?? implode(', ', array_filter($parts));

            if (empty($address) && !empty($parts)) {
                $address = implode(', ', array_filter($parts));
            }
        }

        return [
            'id' => $pancakeOrder['id'],
            'shop_id' => $pancakeOrder['shop_id'] ?? null,
            'customer_name' => $pancakeOrder['bill_full_name'] ?? 'Unknown',
            'customer_email' => $pancakeOrder['bill_email'] ?? '',
            'customer_phone' => $pancakeOrder['bill_phone_number'] ?? '',
            'shipping_address' => $address,

            'total_amount' => $pancakeOrder['total_amount'] ?? 0,

            'status' => $pancakeOrder['status_name'] ?? ($pancakeOrder['status'] ?? 'pending'),
            'payment_status' => $pancakeOrder['payment_type'] ?? ($pancakeOrder['payment_status'] ?? 'unpaid'),
            'payment_method' => $pancakeOrder['payment_method'] ?? ($pancakeOrder['payment_type'] ?? 'cod'),
            'transaction_id' => $pancakeOrder['transaction_id'] ?? null,

            'created_at' => $pancakeOrder['inserted_at'] ?? null,

            'items' => collect($pancakeOrder['items'] ?? [])->map(function ($item) {
                $variationInfo = $item['variation_info'] ?? [];

                $image = null;
                if (is_array($variationInfo) && !empty($variationInfo['images'])) {
                    $image = $variationInfo['images'][0] ?? null;
                }

                $variationString = $item['variation_name'] ?? '';
                if (empty($variationString)) {
                    if (is_string($variationInfo)) {
                        $variationString = $variationInfo;
                    } elseif (is_array($variationInfo)) {
                        $variationString = $variationInfo['name'] ?? ($variationInfo['value'] ?? '');
                    }
                }

                return [
                    'product_name' => $item['product_name'] ?? 'Unknown Item',
                    'quantity' => $item['quantity'] ?? 0,
                    'price' => $item['price'] ?? 0,
                    'variation_info' => $variationString,
                    'image' => $image,
                ];
            }),
            'shipping_fee' => $pancakeOrder['shipping_fee'] ?? 0,
            'cod' => $pancakeOrder['cod'] ?? 0,
            'partner' => $pancakeOrder['partner'] ?? null,
            'note' => $pancakeOrder['note'] ?? '',
        ];
    }

    // ==========================================
    // CUSTOMERS API
    // ==========================================
    public function getCustomers($page = 1, $limit = 30, $search = null)
    {
        $queryParams = ['api_key' => $this->apiKey, 'page_number' => $page, 'page_size' => $limit];
        if ($search)
            $queryParams['search'] = $search;

        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/customers", $queryParams);
        if ($response->failed())
            throw new \Exception('Failed to fetch customers: ' . $response->body());

        $data = $response->json();
        return new LengthAwarePaginator($data['data'] ?? [], $data['total_entries'] ?? 0, $limit, $page);
    }

    public function createCustomer($data)
    {
        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/customers?api_key={$this->apiKey}", $data);
        if ($response->failed())
            throw new \Exception('Failed to create customer: ' . $response->body());
        return $response->json()['data'] ?? $response->json();
    }

    public function updateCustomer($id, $data)
    {
        $response = Http::put("{$this->baseUrl}/shops/{$this->shopId}/customers/{$id}?api_key={$this->apiKey}", $data);
        if ($response->failed())
            throw new \Exception('Failed to update customer: ' . $response->body());
        return $response->json()['data'] ?? $response->json();
    }

    // ==========================================
    // TRANSACTIONS API
    // ==========================================
    public function getTransactions($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/transactions", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed())
            throw new \Exception('Failed to fetch transactions: ' . $response->body());

        $data = $response->json();
        return new LengthAwarePaginator($data['data'] ?? [], $data['total_entries'] ?? 0, $limit, $page);
    }

    public function createTransaction($data)
    {
        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/transactions?api_key={$this->apiKey}", $data);
        if ($response->failed())
            throw new \Exception('Failed to create transaction: ' . $response->body());
        return $response->json()['data'] ?? $response->json();
    }

    // ==========================================
    // PURCHASES API
    // ==========================================
    public function getPurchases($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/purchases", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed())
            throw new \Exception('Failed to fetch purchases: ' . $response->body());

        $data = $response->json();
        return new LengthAwarePaginator($data['data'] ?? [], $data['total_entries'] ?? 0, $limit, $page);
    }

    public function createPurchase($data)
    {
        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/purchases?api_key={$this->apiKey}", $data);
        if ($response->failed())
            throw new \Exception('Failed to create purchase: ' . $response->body());
        return $response->json()['data'] ?? $response->json();
    }

    public function updatePurchase($id, $data)
    {
        $response = Http::put("{$this->baseUrl}/shops/{$this->shopId}/purchases/{$id}?api_key={$this->apiKey}", $data);
        if ($response->failed())
            throw new \Exception('Failed to update purchase: ' . $response->body());
        return $response->json()['data'] ?? $response->json();
    }

    // ==========================================
    // PROMOTIONS API
    // ==========================================
    private function formatMarketingDates($items)
    {
        return collect($items)->map(function ($item) {
            $startDate = null;
            $endDate = null;

            if (!empty($item['start_time']) && strpos($item['start_time'], '1970-01-01') === false) {
                $startDate = date('Y-m-d\TH:i:s', strtotime($item['start_time']));
            }
            if (!empty($item['end_time']) && strpos($item['end_time'], '1970-01-01') === false) {
                $endDate = date('Y-m-d\TH:i:s', strtotime($item['end_time']));
            }

            $item['start_date'] = $startDate;
            $item['end_date'] = $endDate;
            return $item;
        })->values();
    }

    public function getPromotions($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/promotion_advance", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed())
            throw new \Exception('Failed to fetch promotions: ' . $response->body());

        $data = $response->json();
        $items = $this->formatMarketingDates($data['data'] ?? []);
        return new LengthAwarePaginator($items, $data['total_entries'] ?? 0, $limit, $page);
    }



    // ==========================================
    // VOUCHERS API
    // ==========================================
    public function getVouchers($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/vouchers", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed())
            throw new \Exception('Failed to fetch vouchers: ' . $response->body());

        $data = $response->json();
        $items = $this->formatMarketingDates($data['data'] ?? []);
        return new LengthAwarePaginator($items, $data['total_entries'] ?? 0, $limit, $page);
    }



    // ==========================================
    // COMBOS API
    // ==========================================
    public function getCombos($page = 1, $limit = 30)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/combo_products", [
            'api_key' => $this->apiKey,
            'page_number' => $page,
            'page_size' => $limit
        ]);
        if ($response->failed())
            throw new \Exception('Failed to fetch combos: ' . $response->body());

        $data = $response->json();
        $items = $this->formatMarketingDates($data['data'] ?? []);
        return new LengthAwarePaginator($items, $data['total_entries'] ?? 0, $limit, $page);
    }

    public function createCombo($data)
    {
        $startTime = isset($data['start_date']) && !empty($data['start_date']) ? strtotime($data['start_date']) : 1;
        $endTime = isset($data['end_date']) && !empty($data['end_date']) ? strtotime($data['end_date']) : 1;

        $payload = [
            'combo_product' => [
                'name' => $data['name'] ?? 'New Combo',
                'currency' => 'VND',
                'start_time' => $startTime,
                'end_time' => $endTime,
                'is_value_combo' => true,
                'value_combo' => isset($data['price']) && is_numeric($data['price']) ? (int) $data['price'] : 0,
                'is_use_percent' => false,
                'is_variation' => false,
                'variations' => []
            ]
        ];

        $response = Http::post("{$this->baseUrl}/shops/{$this->shopId}/combo_products?api_key={$this->apiKey}", $payload);
        if ($response->failed())
            throw new \Exception('Failed to create combo: ' . $response->body());
        return $response->json()['data'] ?? $response->json();
    }

    public function updateCombo($id, $data)
    {
        $startTime = isset($data['start_date']) && !empty($data['start_date']) ? strtotime($data['start_date']) : 1;
        $endTime = isset($data['end_date']) && !empty($data['end_date']) ? strtotime($data['end_date']) : 1;

        $data['start_time'] = $startTime;
        $data['end_time'] = $endTime;
        $data['value_combo'] = isset($data['price']) && is_numeric($data['price']) ? (int) $data['price'] : ($data['value_combo'] ?? 0);
        $data['is_activated'] = ($data['status'] ?? 'active') === 'active';

        unset($data['start_date']);
        unset($data['end_date']);
        unset($data['price']);
        unset($data['status']);

        $payload = ['combo_product' => $data];

        $response = Http::put("{$this->baseUrl}/shops/{$this->shopId}/combo_products/{$id}?api_key={$this->apiKey}", $payload);
        if ($response->failed())
            throw new \Exception('Failed to update combo: ' . $response->body());
        return $response->json()['data'] ?? $response->json();
    }

    public function deleteCombo($id)
    {
        $response = Http::delete("{$this->baseUrl}/shops/{$this->shopId}/combo_products/{$id}?api_key={$this->apiKey}");
        if ($response->failed())
            throw new \Exception('Failed to delete combo: ' . $response->body());
        return true;
    }

    // ==========================================
    // STATISTICS & ANALYTICS API
    // ==========================================
    public function getSalesAnalytics($startDate, $endDate)
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/analytics/sale", [
            'api_key' => $this->apiKey,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        if ($response->failed())
            throw new \Exception('Failed to fetch sales analytics: ' . $response->body());
        return $response->json()['data'] ?? $response->json();
    }

    public function getInventoryAnalytics()
    {
        $response = Http::get("{$this->baseUrl}/shops/{$this->shopId}/inventory_analytics/inventory", [
            'api_key' => $this->apiKey
        ]);
        if ($response->failed())
            throw new \Exception('Failed to fetch inventory analytics: ' . $response->body());
        return $response->json()['data'] ?? $response->json();
    }
}
