<?php

namespace Database\Factories;

use App\Models\Option;
use App\Models\OptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Option>
 */
class OptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'option_group_id' => OptionGroup::factory(),
            'name' => fake()->word(),
            'price_cents' => fake()->numberBetween(0, 1500),
            'is_active' => true,
        ];
    }
}
