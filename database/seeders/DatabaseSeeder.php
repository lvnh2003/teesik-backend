<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Admin User
        \App\Models\User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@teesik.com',
            'password' => bcrypt('password'), // password
            'role' => 'admin',
        ]);

        // 2. Create Regular Users
        \App\Models\User::factory()->create([
            'name' => 'Demo User',
            'email' => 'user@teesik.com',
            'password' => bcrypt('password'),
            'role' => 'user',
        ]);

        $users = \App\Models\User::factory(10)->create();

        // 3. Create Categories
        $categories = \App\Models\Category::factory(5)->create();

        // 4. Create Products
        $categories->each(function ($category) {
            \App\Models\Product::factory(4)->create([
                'category_id' => $category->id
            ])->each(function ($product) {
                // Create Variants
                \App\Models\ProductVariant::factory(3)->create([
                    'product_id' => $product->id
                ]);

                // Create Images
                \App\Models\ProductImage::factory(2)->create([
                    'product_id' => $product->id
                ]);
            });
        });

        // 5. Create Orders for random users
        $products = \App\Models\Product::all();
        $variants = \App\Models\ProductVariant::all();

        $users->each(function ($user) use ($products, $variants) {
            \App\Models\Order::factory(rand(0, 3))->create([
                'user_id' => $user->id
            ])->each(function ($order) use ($products, $variants) {
                // Create Order Items
                $numItems = rand(1, 3);
                for ($i = 0; $i < $numItems; $i++) {
                    $product = $products->random();
                    $variant = $variants->where('product_id', $product->id)->first();

                    \App\Models\OrderItem::factory()->create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant ? $variant->id : null,
                        'product_name' => $product->name,
                        'price' => $variant ? $variant->price : $product->price,
                    ]);
                }

                // Recalculate total amount
                $total = $order->items->sum(function ($item) {
                    return $item->price * $item->quantity;
                });
                $order->update(['total_amount' => $total]);
            });
        });
    }
}
