<?php

namespace Database\Factories;

use App\Enums\CourierStatus;
use App\Enums\PixKeyType;
use App\Enums\Vehicle;
use App\Models\NetworkCourier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkCourier>
 */
class NetworkCourierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cpf = self::cpf();

        return [
            'user_id' => User::factory(),
            'cpf' => $cpf,
            'phone' => '55119'.fake()->numerify('########'),
            'city' => 'São Paulo',
            'state' => 'SP',
            'vehicle' => Vehicle::Motorcycle,
            'vehicle_plate' => 'ABC1D23',
            'pix_key_type' => PixKeyType::Cpf,
            'pix_key' => $cpf,
            'document_path' => null,
            'status' => CourierStatus::Pending,
        ];
    }

    /**
     * Approved, online and standing at that point.
     */
    public function onlineAt(float $latitude, float $longitude): static
    {
        return $this->state([
            'status' => CourierStatus::Approved,
            'is_online' => true,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'located_at' => now(),
        ]);
    }

    /**
     * A random CPF with valid check digits.
     */
    public static function cpf(): string
    {
        $digits = array_map(fn () => random_int(0, 9), range(1, 9));

        foreach ([9, 10] as $length) {
            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += $digits[$i] * ($length + 1 - $i);
            }

            $digits[] = (10 * $sum) % 11 % 10;
        }

        return implode('', $digits);
    }
}
