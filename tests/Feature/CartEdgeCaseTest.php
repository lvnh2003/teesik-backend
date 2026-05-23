<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_add_rejects_unknown_variant_for_variant_product(): void
    {
        Product::create([
            'pancake_id' => 'product-1',
            'name' => 'Test Shirt',
            'slug' => 'test-shirt',
            'price' => 100000,
            'is_active' => true,
            'variations' => [
                [
                    'id' => 'variant-1',
                    'price' => 120000,
                    'stock_quantity' => 10,
                    'attributes' => ['Size' => 'M'],
                ],
            ],
            'data' => ['is_sell_negative' => false],
        ]);

        $response = $this
            ->withHeader('X-Cart-ID', 'cart-1')
            ->postJson('/api/v1/cart/add', [
                'product_id' => 'product-1',
                'variant_id' => 'missing-variant',
                'quantity' => 1,
            ]);

        $response
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Product variation not found in local catalog');

        $this->assertSame(0, CartItem::count());
    }

    public function test_cart_add_rejects_quantity_above_variant_stock(): void
    {
        Product::create([
            'pancake_id' => 'product-2',
            'name' => 'Limited Shirt',
            'slug' => 'limited-shirt',
            'price' => 100000,
            'is_active' => true,
            'variations' => [
                [
                    'id' => 'variant-2',
                    'price' => 120000,
                    'stock_quantity' => 3,
                    'attributes' => ['Size' => 'M'],
                ],
            ],
            'data' => ['is_sell_negative' => false],
        ]);

        $this
            ->withHeader('X-Cart-ID', 'cart-2')
            ->postJson('/api/v1/cart/add', [
                'product_id' => 'product-2',
                'variant_id' => 'variant-2',
                'quantity' => 3,
            ])
            ->assertOk();

        $response = $this
            ->withHeader('X-Cart-ID', 'cart-2')
            ->postJson('/api/v1/cart/add', [
                'product_id' => 'product-2',
                'variant_id' => 'variant-2',
                'quantity' => 1,
            ]);

        $response
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Requested quantity exceeds available stock');

        $this->assertSame(3, CartItem::first()->quantity);
    }

    public function test_cart_add_normalizes_null_quantity_to_one(): void
    {
        Product::create([
            'pancake_id' => 'product-null-quantity',
            'name' => 'Default Quantity Shirt',
            'slug' => 'default-quantity-shirt',
            'price' => 100000,
            'is_active' => true,
            'data' => ['is_sell_negative' => false],
        ]);

        $this
            ->withHeader('X-Cart-ID', 'cart-null-quantity')
            ->postJson('/api/v1/cart/add', [
                'product_id' => 'product-null-quantity',
                'quantity' => null,
            ])
            ->assertOk();

        $this->assertSame(1, CartItem::first()->quantity);
    }

    public function test_cart_update_rejects_quantity_above_variant_stock(): void
    {
        Product::create([
            'pancake_id' => 'product-3',
            'name' => 'Small Batch Shirt',
            'slug' => 'small-batch-shirt',
            'price' => 100000,
            'is_active' => true,
            'variations' => [
                [
                    'id' => 'variant-3',
                    'price' => 120000,
                    'stock_quantity' => 2,
                    'attributes' => ['Size' => 'M'],
                ],
            ],
            'data' => ['is_sell_negative' => false],
        ]);

        $this
            ->withHeader('X-Cart-ID', 'cart-3')
            ->postJson('/api/v1/cart/add', [
                'product_id' => 'product-3',
                'variant_id' => 'variant-3',
                'quantity' => 1,
            ])
            ->assertOk();

        $response = $this
            ->withHeader('X-Cart-ID', 'cart-3')
            ->postJson('/api/v1/cart/update', [
                'product_id' => 'product-3',
                'variant_id' => 'variant-3',
                'quantity' => 3,
            ]);

        $response
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Requested quantity exceeds available stock');

        $this->assertSame(1, CartItem::first()->quantity);
    }
}
