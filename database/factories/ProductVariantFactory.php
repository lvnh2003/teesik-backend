<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => \App\Models\Product::factory(),
            'color' => $this->faker->safeColorName(),
            'size' => $this->faker->randomElement(['S', 'M', 'L', 'XL', 'XXL']),
            'sku' => strtoupper($this->faker->unique()->bothify('TS-##??')),
            'price' => $this->faker->numberBetween(100000, 2000000),
            'stock_quantity' => $this->faker->numberBetween(0, 100),
            'image' => 'https://placehold.co/400x400?text=Variant',
            'attributes' => ['material' => 'cotton'], // As it is cast to array
        ];
    }
}
