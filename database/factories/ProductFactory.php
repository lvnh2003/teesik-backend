<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->words(3, true);
        return [
            'name' => ucfirst($name),
            'slug' => \Illuminate\Support\Str::slug($name),
            'description' => $this->faker->paragraph(),
            'price' => $this->faker->numberBetween(100000, 2000000), // 100k - 2m VND
            'original_price' => $this->faker->optional(0.3)->numberBetween(2100000, 3000000),
            'sku' => strtoupper($this->faker->unique()->bothify('PRD-#####')),
            'stock_quantity' => $this->faker->numberBetween(0, 100),
            'category_id' => \App\Models\Category::factory(),
            'status' => $this->faker->randomElement(['published', 'draft', 'archived']),
            'is_new' => $this->faker->boolean(20),
            'is_featured' => $this->faker->boolean(10),
        ];
    }
}
