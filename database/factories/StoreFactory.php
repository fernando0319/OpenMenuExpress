<?php

namespace Database\Factories;

use App\Enums\DeliveryFeeMode;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'whatsapp' => '55119'.fake()->numerify('########'),
            'timezone' => 'America/Sao_Paulo',
            'minimum_order_cents' => 0,
            'delivery_fee_mode' => DeliveryFeeMode::Zone,
        ];
    }
}
