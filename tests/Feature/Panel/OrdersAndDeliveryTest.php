<?php

namespace Tests\Feature\Panel;

use App\Enums\Fulfillment;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrdersAndDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->owner = $this->memberOf($this->store, StoreRole::Owner);
    }

    public function test_orders_list_and_detail_render_only_for_the_store(): void
    {
        $order = $this->makeOrder($this->store);
        $foreign = $this->makeOrder(Store::factory()->create());

        $this->actingAs($this->owner)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $order->id));

        $this->actingAs($this->owner)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->component('orders/Show'));

        $this->actingAs($this->owner)
            ->get(route('orders.show', $foreign))
            ->assertNotFound();
    }

    public function test_attendant_sees_orders_but_not_store_settings(): void
    {
        $attendant = $this->memberOf($this->store, StoreRole::Attendant);

        $this->actingAs($attendant)->get(route('orders.index'))->assertOk();
        $this->actingAs($attendant)->get(route('store-settings.delivery.edit'))->assertForbidden();
    }

    public function test_owner_configures_delivery_and_zones(): void
    {
        $this->actingAs($this->owner)
            ->get(route('store-settings.delivery.edit'))
            ->assertInertia(fn (Assert $page) => $page->component('store-settings/Delivery'));

        $this->actingAs($this->owner)
            ->put(route('store-settings.delivery.update'), [
                'accepts_delivery' => true,
                'accepts_pickup' => false,
                'is_paused' => false,
                'minimum_order' => '30,00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(3000, $this->store->fresh()?->minimum_order_cents);
        $this->assertFalse($this->store->fresh()->accepts_pickup);

        $this->actingAs($this->owner)
            ->post(route('store-settings.delivery.zones.store'), ['name' => 'Centro', 'fee' => '6,50'])
            ->assertSessionHasNoErrors();

        $zone = $this->store->deliveryZones()->sole();
        $this->assertSame(650, $zone->fee_cents);

        $otherZone = DeliveryZone::query()->create(['store_id' => Store::factory()->create()->id, 'name' => 'X', 'fee_cents' => 0]);
        $this->actingAs($this->owner)
            ->delete(route('store-settings.delivery.zones.destroy', $otherZone))
            ->assertNotFound();
    }

    private function makeOrder(Store $store): Order
    {
        return $store->orders()->create([
            'number' => 1,
            'public_token' => str_repeat('a', 31).$store->id,
            'customer_name' => 'Maria',
            'customer_phone' => '5511999998888',
            'channel' => OrderChannel::Online,
            'fulfillment' => Fulfillment::Pickup,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 1000,
            'total_cents' => 1000,
            'payment_method' => 'pix',
            'placed_at' => now(),
        ]);
    }

    private function memberOf(Store $store, StoreRole $role): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
