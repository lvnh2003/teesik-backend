<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:20',
            'customer_email' => 'nullable|email|max:255',
            'shipping_address' => 'required|string|max:500',
            'payment_method' => 'required|string|in:cod,vnpay,momo',
            'voucher_code' => 'nullable|string',
            
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|string',
            'items.*.variation_id' => 'nullable|string',
            'items.*.quantity' => 'required|integer|min:1',
        ];
    }
}
