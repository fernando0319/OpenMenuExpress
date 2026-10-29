<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->randomElement(['Lanches', 'Pizzas', 'Bebidas', 'Sobremesas', 'Porções']),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
