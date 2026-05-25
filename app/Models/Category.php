<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'pancake_id',
        'name',
        'slug',
        'data',
        'synced_at',
    ];

    protected $casts = [
        'data' => 'array',
        'synced_at' => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => is_numeric($this->pancake_id) ? (int) $this->pancake_id : ($this->pancake_id ?? $this->id),
            'name' => $this->name,
            'slug' => $this->slug,
        ];
    }
}
