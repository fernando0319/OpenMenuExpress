<?php

namespace Tests\Feature\CourierNetwork;

use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Models\NetworkCourier;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\NetworkSettings;

/**
 * A store on the network at a known point, and couriers placed some km north of it.
 */
trait BuildsNetwork
{
    protected Store $store;

    protected User $owner;

    protected const STORE_LAT = -23.55;

    protected const STORE_LNG = -46.63;

    protected function setUpNetwork(): void
    {
        NetworkSettings::save([
            'enabled' => true,
            'platform_fee_bps' => 1500,
            'min_fee_cents' => 500,
            'search_radius_km' => 8.0,
            'bill_grace_days' => 1,
            'pix_key' => 'financeiro@exemplo.com',
        ]);

        $this->store = Store::factory()->create([
            'slug' => 'pizzaria-ze',
            'name' => 'Pizzaria do Zé',
            'network_enabled' => true,
            'network_fee_cents' => 800,
            'latitude' => self::STORE_LAT,
            'longitude' => self::STORE_LNG,
            'address_street' => 'Rua A',
            'address_number' => '10',
            'address_district' => 'Centro',
        ]);

        $this->owner = User::factory()->create(['name' => 'Zé Dono']);
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    protected function courierKmNorth(float $km, string $name = 'Carlos Motoboy'): NetworkCourier
    {
        return NetworkCourier::factory()
            ->for(User::factory()->state(['name' => $name]))
            ->onlineAt(self::STORE_LAT + $km / 111.2, self::STORE_LNG)
            ->create();
    }

    protected function deliveryOrder(OrderStatus $status = OrderStatus::Accepted, string $payment = 'cash'): Order
    {
        return Order::query()->create([
            'store_id' => $this->store->id,
            'number' => (int) $this->store->orders()->max('number') + 1,
            'customer_name' => 'Maria',
            'customer_phone' => '5511999998888',
            'channel' => 'online',
            'fulfillment' => 'delivery',
            'status' => $status,
            'delivery_street' => 'Rua das Flores',
            'delivery_number' => '120',
            'delivery_district' => 'Jardins',
            'delivery_city' => 'São Paulo',
            'subtotal_cents' => 4000,
            'discount_cents' => 0,
            'delivery_fee_cents' => 700,
            'total_cents' => 4700,
            'payment_method' => $payment,
            'change_for_cents' => $payment === 'cash' ? 5000 : null,
            'placed_at' => now(),
        ]);
    }
}
