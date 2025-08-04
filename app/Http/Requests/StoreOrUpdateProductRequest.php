<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrUpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|exists:categories,id',

            'variants' => 'nullable|array',
            'variants.*.sku' => [
                'required_with:variants',
                'string',
                function ($attribute, $value, $fail) {
                    $index = explode('.', $attribute)[1];
                    $variantId = $this->input("variants.$index.id");

                    $exists = \DB::table('product_variants')
                        ->where('sku', $value)
                        ->when($variantId, fn($q) => $q->where('id', '!=', $variantId))
                        ->exists();

                    if ($exists) {
                        $fail("SKU của biến thể đã tồn tại.");
                    }
                },
            ],
            'variants.*.price' => 'required_with:variants|numeric|min:0',
            'variants.*.original_price' => 'nullable|numeric|min:0',
            'variants.*.stock_quantity' => 'required_with:variants|integer|min:0',
            'variants.*.attributes' => 'required_with:variants|array',

            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên sản phẩm là bắt buộc.',
            'description.required' => 'Mô tả sản phẩm là bắt buộc.',
            'category_id.required' => 'Danh mục là bắt buộc.',
            'category_id.exists' => 'Danh mục không hợp lệ.',

            'variants.*.sku.required_with' => 'Mỗi biến thể phải có SKU.',
            'variants.*.sku.unique' => 'SKU của biến thể đã tồn tại.',
            'variants.*.price.required_with' => 'Mỗi biến thể phải có giá bán.',
            'variants.*.price.numeric' => 'Giá bán phải là số.',
            'variants.*.stock_quantity.required_with' => 'Mỗi biến thể phải có số lượng.',
            'variants.*.stock_quantity.integer' => 'Số lượng phải là số nguyên.',
            'variants.*.attributes.required_with' => 'Mỗi biến thể phải có thuộc tính.',

            'images.*.image' => 'Tệp tải lên phải là ảnh.',
            'images.*.mimes' => 'Ảnh phải có định dạng jpeg, png, jpg hoặc gif.',
            'images.*.max' => 'Ảnh không được vượt quá 5MB.',
        ];
    }
}
