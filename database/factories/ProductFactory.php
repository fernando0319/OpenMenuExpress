<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
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
        return [
            'category_id' => Category::factory(),
            'store_id' => fn (array $attributes) => Category::query()->whereKey($attributes['category_id'])->firstOrFail()->store_id,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'price_cents' => fake()->numberBetween(500, 8000),
            'is_active' => true,
        ];
    }

    public function onPromotion(int $promotionalPriceCents): static
    {
        return $this->state(['promotional_price_cents' => $promotionalPriceCents]);
    }
}
