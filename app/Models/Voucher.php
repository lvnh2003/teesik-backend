<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'pancake_id',
        'code',
        'name',
        'description',
        'is_activated',
        'is_percent',
        'discount_value',
        'max_discount',
        'min_order_value',
        'usage_limit',
        'used_count',
        'start_date',
        'end_date',
        'data',
        'synced_at',
    ];

    protected $casts = [
        'is_activated' => 'boolean',
        'is_percent' => 'boolean',
        'discount_value' => 'float',
        'max_discount' => 'float',
        'min_order_value' => 'float',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'data' => 'array',
        'synced_at' => 'datetime',
    ];

    public function toApiArray(): array
    {
        return [
            'id' => $this->pancake_id ?? $this->id,
            'code' => $this->code,
            'name' => $this->name ?? $this->code,
            'description' => $this->description ?? '',
            'is_activated' => $this->is_activated,
            'is_percent' => $this->is_percent,
            'discount_value' => $this->discount_value,
            'max_discount' => $this->max_discount,
            'min_order_value' => $this->min_order_value,
            'usage_limit' => $this->usage_limit,
            'used_count' => $this->used_count,
            'remaining' => $this->usage_limit > 0 ? max(0, $this->usage_limit - $this->used_count) : null,
            'start_date' => $this->start_date?->toDateTimeString(),
            'end_date' => $this->end_date?->toDateTimeString(),
            'raw' => $this->data ?? [],
        ];
    }
}
