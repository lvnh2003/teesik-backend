<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Gắn foreign key tới bảng products không có foreignId ràng buộc ở DB level do format (có thể không tuân thủ strict)
    // nhưng eloquent vẫn join theo id.
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
