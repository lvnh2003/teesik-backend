<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'pancake_id',
        'category_id',
        'name',
        'slug',
        'sku',
        'price',
        'original_price',
        'category_ids',
        'images',
        'variations',
        'data',
        'is_active',
        'synced_at',
    ];

    protected $casts = [
        'price' => 'float',
        'original_price' => 'float',
        'category_ids' => 'array',
        'images' => 'array',
        'variations' => 'array',
        'data' => 'array',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function toApiArray(): array
    {
        $data = $this->data ?? [];
        $variations = $this->variations ?? [];
        $images = $this->images ?? [];

        if (empty($images)) {
            foreach ($variations as $variation) {
                foreach (($variation['images'] ?? []) as $image) {
                    $imagePath = is_array($image) ? ($image['image_path'] ?? null) : $image;
                    if ($imagePath) {
                        $images[] = is_array($image) ? $image : ['id' => null, 'image_path' => $imagePath];
                    }
                }
            }

            $images = collect($images)
                ->filter(fn($image) => !empty($image['image_path']))
                ->unique('image_path')
                ->values()
                ->toArray();
        }

        $data['id'] = $this->pancake_id;
        $data['name'] = $this->name;
        $data['sku'] = $this->sku;
        $data['price'] = $this->price;
        $data['original_price'] = $this->original_price;
        $data['slug'] = $this->slug;
        $data['images'] = $images;
        $data['main_image'] = $data['main_image'] ?? ($images[0] ?? null);
        $data['variations'] = $variations;
        $data['category_ids'] = $this->category_ids ?? [];
        $data['category_id'] = $this->category?->pancake_id ?? $data['category_id'] ?? null;
        $data['category'] = $this->category?->toApiArray() ?? $data['category'] ?? null;
        $data['synced_at'] = $this->synced_at?->toISOString();

        return $data;
    }
}
