<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class LocalProductRepository
{
    public function paginate(int $page = 1, int $limit = 8, ?string $search = null, $categoryId = null): LengthAwarePaginator
    {
        $query = Product::query()->with('category')->where('is_active', true);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($categoryId) {
            $category = Category::query()
                ->where('pancake_id', (string) $categoryId)
                ->orWhere('id', $categoryId)
                ->first();

            if ($category) {
                $query->where(function ($q) use ($category) {
                    $q->where('category_id', $category->id)
                        ->orWhereJsonContains('category_ids', $category->pancake_id)
                        ->orWhereJsonContains('category_ids', (int) $category->pancake_id);
                });
            }
        }

        $paginator = $query->orderByDesc('synced_at')
            ->orderBy('name')
            ->paginate($limit, ['*'], 'page', $page);

        return new LengthAwarePaginator(
            $paginator->getCollection()->map(fn(Product $product) => $product->toApiArray())->values(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function findByPancakeId($id): ?array
    {
        $product = Product::query()->with('category')
            ->where('pancake_id', (string) $id)
            ->first();

        return $product?->toApiArray();
    }

    public function categories()
    {
        return Category::query()
            ->orderBy('name')
            ->get()
            ->map(fn(Category $category) => $category->toApiArray())
            ->values();
    }

    public function upsertCategory(array $categoryData): Category
    {
        $pancakeId = (string) ($categoryData['id'] ?? $categoryData['pancake_id'] ?? '');
        $name = $categoryData['name'] ?? 'Unknown';

        return Category::updateOrCreate(
            ['pancake_id' => $pancakeId],
            [
                'name' => $name,
                'slug' => $categoryData['slug'] ?? Str::slug($name),
                'data' => $categoryData,
                'synced_at' => now(),
            ]
        );
    }

    public function upsertProduct(array $productData): Product
    {
        $pancakeId = (string) ($productData['id'] ?? '');
        if ($pancakeId === '') {
            throw new \InvalidArgumentException('Product is missing id.');
        }

        $category = null;
        $primaryCategoryId = $productData['category']['id'] ?? $productData['category_id'] ?? null;
        if ($primaryCategoryId) {
            $category = Category::query()->where('pancake_id', (string) $primaryCategoryId)->first();
        }

        $images = $productData['images'] ?? [];
        if (empty($images)) {
            foreach (($productData['variations'] ?? []) as $variation) {
                foreach (($variation['images'] ?? []) as $image) {
                    $imagePath = is_array($image) ? ($image['image_path'] ?? null) : $image;
                    if ($imagePath) {
                        $images[] = is_array($image) ? $image : ['id' => null, 'image_path' => $imagePath];
                    }
                }
            }
            $images = collect($images)->filter(fn($image) => !empty($image['image_path']))->unique('image_path')->values()->toArray();
        }

        $productData['images'] = $images;
        $productData['main_image'] = $productData['main_image'] ?? ($images[0] ?? null);

        return Product::updateOrCreate(
            ['pancake_id' => $pancakeId],
            [
                'category_id' => $category?->id,
                'name' => $productData['name'] ?? 'Unknown Product',
                'slug' => $productData['slug'] ?? Str::slug($productData['name'] ?? $pancakeId),
                'sku' => $productData['sku'] ?? null,
                'price' => (float) ($productData['price'] ?? 0),
                'original_price' => (float) ($productData['original_price'] ?? 0),
                'category_ids' => $productData['category_ids'] ?? [],
                'images' => $images,
                'variations' => $productData['variations'] ?? [],
                'data' => $productData,
                'is_active' => !filter_var($productData['is_hidden'] ?? false, FILTER_VALIDATE_BOOL),
                'synced_at' => now(),
            ]
        );
    }

    public function resolveOrderItem($productId, $variationId = null, $quantity = 1): array
    {
        $product = Product::query()->where('pancake_id', (string) $productId)->first();
        if (!$product) {
            throw new \Exception('Product not found in local catalog');
        }

        if (!$product->is_active) {
            throw new \Exception('Product is no longer available');
        }

        $resolvedVariationId = $variationId;
        $name = $product->name;
        $price = (float) $product->price;
        $attributes = [];
        $requestedQuantity = max(1, min(99, (int) $quantity));

        $variations = $product->variations ?? [];
        if (!empty($variations)) {
            if (!$resolvedVariationId || (string) $resolvedVariationId === (string) $productId) {
                $resolvedVariationId = $variations[0]['id'] ?? null;
            }

            $variation = collect($variations)->first(function ($item) use ($resolvedVariationId) {
                return (string) ($item['id'] ?? '') === (string) $resolvedVariationId;
            });

            if (!$variation) {
                throw new \Exception('Product variation not found in local catalog');
            }

            $price = (float) ($variation['price'] ?? $price);
            $attributes = $variation['attributes'] ?? [];
            if (!empty($attributes)) {
                $name .= ' (' . implode(', ', array_values($attributes)) . ')';
            }

            $isHidden = filter_var($variation['is_hidden'] ?? false, FILTER_VALIDATE_BOOL);
            $isDeleted = filter_var($variation['isDelete'] ?? false, FILTER_VALIDATE_BOOL);
            if ($isHidden || $isDeleted) {
                throw new \Exception('Product variation is no longer available');
            }

            $stockQuantity = $variation['stock_quantity'] ?? null;
            $allowsNegativeStock = (bool) data_get($product->data, 'is_sell_negative', false);
            if ($stockQuantity !== null && !$allowsNegativeStock && $requestedQuantity > (int) $stockQuantity) {
                throw new \Exception('Requested quantity exceeds available stock');
            }
        }

        if ($price <= 0) {
            throw new \Exception('Product price is invalid in local catalog');
        }

        return [
            'product_id' => (string) $productId,
            'variation_id' => $resolvedVariationId,
            'quantity' => $requestedQuantity,
            'price' => $price,
            'name' => $name,
            'attributes' => $attributes,
        ];
    }
}
