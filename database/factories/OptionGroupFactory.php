<?php

namespace Database\Factories;

use App\Enums\PricingMode;
use App\Models\OptionGroup;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionGroup>
 */
class OptionGroupFactory extends Factory
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
            'name' => 'Adicionais',
            'min_choices' => 0,
            'max_choices' => 5,
            'pricing_mode' => PricingMode::Sum,
            'is_active' => true,
        ];
    }

    public function pizzaFlavors(PricingMode $mode = PricingMode::Highest): static
    {
        return $this->state([
            'name' => 'Sabores',
            'min_choices' => 1,
            'max_choices' => 2,
            'pricing_mode' => $mode,
        ]);
    }
}
