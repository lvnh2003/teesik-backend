<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'pancake_id',
        'user_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'total_amount',
        'discount_amount',
        'shipping_fee',
        'grand_total',
        'status',
        'payment_status',
        'payment_method',
        'transaction_id',
        'items',
        'data',
        'synced_at',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'discount_amount' => 'float',
        'shipping_fee' => 'float',
        'grand_total' => 'float',
        'items' => 'array',
        'data' => 'array',
        'synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->pancake_id ?? $this->id,
            'shop_id' => $this->data['shop_id'] ?? null,
            'user_id' => $this->user_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email ?? '',
            'customer_phone' => $this->customer_phone ?? '',
            'shipping_address' => $this->shipping_address ?? '',
            'total_amount' => $this->total_amount,
            'discount_amount' => $this->discount_amount,
            'shipping_fee' => $this->shipping_fee,
            'grand_total' => $this->grand_total,
            'cod' => $this->grand_total,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'transaction_id' => $this->transaction_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'items' => $this->items ?? [],
            'partner' => $this->data['partner'] ?? null,
            'note' => $this->data['note'] ?? '',
        ];
    }
}
