<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = ['cart_id', 'product_id', 'product_variant_id', 'quantity', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    // Relationships to Product/Variant removed as we decoupled from DB

    // Accessors for easy access
    public function getNameAttribute()
    {
        return $this->data['name'] ?? 'Unknown Product';
    }

    public function getPriceAttribute()
    {
        return $this->data['price'] ?? 0;
    }

    public function getImageAttribute()
    {
        return $this->data['image'] ?? null;
    }
}
