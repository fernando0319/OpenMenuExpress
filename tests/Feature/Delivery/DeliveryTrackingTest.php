<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\CourierLocation;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class DeliveryTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $courier;

    private Product $product;

    private DeliveryZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze']);
        $this->owner = $this->member(StoreRole::Owner, 'Zé Dono');
        $this->courier = $this->member(StoreRole::Courier, 'Carlos Motoboy');
        $this->product = Product::factory()->for(Category::factory()->for($this->store))->create(['price_cents' => 4000]);
        $this->zone = DeliveryZone::query()->create(['store_id' => $this->store->id, 'name' => 'Centro', 'fee_cents' => 500]);
    }

    public function test_a_delivery_from_assignment_to_doorstep(): void
    {
        $order = $this->deliveryOrder();
        $this->actingAs($this->owner)->patch(route('orders.status.update', $order), ['status' => 'accepted']);

        $this->actingAs($this->owner)
            ->patch(route('orders.courier.update', $order), ['courier_id' => $this->courier->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->courier)
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('delivery/Index')
                ->where('orders.0.number', $order->number)
                ->where('orders.0.address', 'Rua das Flores, 120')
                ->where('orders.0.customerPhone', '5511999998888'));

        // Nothing on the way yet: the position is not kept.
        $this->location()->assertOk()->assertJson(['tracking' => false]);
        $this->assertSame(0, CourierLocation::query()->count());

        $this->actingAs($this->courier)->post(route('deliveries.depart', $order))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()?->status);

        $this->location()->assertOk()->assertJson(['tracking' => true]);
        $this->location(-23.5506)->assertOk(); // a few seconds later: skipped
        $this->assertSame(1, CourierLocation::query()->count());

        $this->get("/pizzaria-ze/orders/{$order->public_token}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('tracking.courierName', 'Carlos')
                ->where('tracking.position.lat', -23.5505)
                ->where('tracking.position.lng', -46.6333)
                ->has('mapTiles.url'));

        $this->actingAs($this->owner)
            ->get(route('couriers.map'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('delivery/Map')
                ->where('couriers.0.name', 'Carlos Motoboy')
                ->where('couriers.0.position.lat', -23.5505)
                ->where('couriers.0.orders.0.number', $order->number));

        $this->actingAs($this->courier)->post(route('deliveries.complete', $order))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Completed, $order->fresh()?->status);

        // Delivered: the customer no longer sees the courier, and new positions are ignored.
        $this->get("/pizzaria-ze/orders/{$order->public_token}")->assertInertia(fn (Assert $page) => $page->where('tracking', null));
        $this->travel(10)->seconds();
        $this->location()->assertJson(['tracking' => false]);
    }

    public function test_only_delivery_orders_of_team_members_get_a_courier(): void
    {
        $pickup = $this->onlineOrder(['fulfillment' => 'pickup']);
        $this->actingAs($this->owner)->patch(route('orders.courier.update', $pickup), ['courier_id' => $this->courier->id])
            ->assertSessionHasErrors('courier_id');

        $order = $this->deliveryOrder();
        $stranger = User::factory()->create();
        $this->actingAs($this->owner)->patch(route('orders.courier.update', $order), ['courier_id' => $stranger->id])
            ->assertSessionHasErrors('courier_id');

        $this->actingAs($this->owner)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.canAssignCourier', true)
                ->where('couriers.0.name', 'Carlos Motoboy')
                ->where('couriers.0.role', 'Entregador'));
    }

    public function test_couriers_only_reach_their_own_deliveries(): void
    {
        $order = $this->deliveryOrder();
        $other = $this->member(StoreRole::Courier, 'Outro');
        $this->actingAs($this->owner)->patch(route('orders.courier.update', $order), ['courier_id' => $other->id]);

        $this->actingAs($this->courier)->get(route('dashboard'))->assertRedirect(route('deliveries.index'));
        $this->actingAs($this->courier)->get(route('orders.index'))->assertForbidden();
        $this->actingAs($this->courier)->get(route('orders.show', $order))->assertForbidden();
        $this->actingAs($this->courier)->get(route('tables.index'))->assertForbidden();
        $this->actingAs($this->courier)->get(route('pos.show'))->assertForbidden();
        $this->actingAs($this->courier)->get(route('couriers.map'))->assertForbidden();
        $this->actingAs($this->courier)->post(route('deliveries.depart', $order))->assertNotFound();

        $this->actingAs($this->courier)
            ->get(route('deliveries.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 0));
    }

    public function test_the_courier_can_be_chosen_when_the_order_leaves(): void
    {
        $order = $this->deliveryOrder();
        $this->actingAs($this->owner)->patch(route('orders.status.update', $order), ['status' => 'accepted']);

        $this->actingAs($this->owner)
            ->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.data.0.isDelivery', true)
                ->where('orders.data.0.courierId', null)
                ->where('couriers.0.name', 'Carlos Motoboy'));

        $this->actingAs($this->owner)
            ->patch(route('orders.status.update', $order), ['status' => 'out_for_delivery', 'courier_id' => $this->courier->id])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::OutForDelivery, $order->status);
        $this->assertSame($this->courier->id, $order->courier_id);

        // A refused move does not leave a courier behind.
        $other = $this->deliveryOrder();
        $this->actingAs($this->owner)
            ->patch(route('orders.status.update', $other), ['status' => 'out_for_delivery', 'courier_id' => $this->courier->id])
            ->assertSessionHasErrors('status');
        $this->assertNull($other->fresh()?->courier_id);
    }

    public function test_a_pdv_delivery_sale_takes_its_courier(): void
    {
        $this->actingAs($this->owner)->post(route('cash.open'), ['opening' => '0']);

        $sale = fn (array $extra) => $this->actingAs($this->owner)->post(route('pos.orders.store'), [
            'fulfillment' => 'delivery',
            'customer' => ['name' => 'João', 'phone' => '11988887777'],
            'address' => ['street' => 'Rua A', 'number' => '1'],
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'pay_later' => true,
            'payment_method' => 'pix',
            ...$extra,
        ]);

        $sale(['courier_id' => User::factory()->create()->id])->assertSessionHasErrors('courier_id');

        $sale(['courier_id' => $this->courier->id])->assertSessionHasNoErrors();
        $this->assertSame($this->courier->id, Order::query()->latest('id')->firstOrFail()->courier_id);

        $this->actingAs($this->courier)
            ->get(route('deliveries.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 1)->where('orders.0.customerName', 'João'));
    }

    public function test_positions_are_validated_and_pruned_after_a_week(): void
    {
        $this->actingAs($this->courier)->postJson(route('deliveries.location'), ['latitude' => 120, 'longitude' => 0])
            ->assertUnprocessable();

        CourierLocation::query()->create(['store_id' => $this->store->id, 'user_id' => $this->courier->id, 'latitude' => 1, 'longitude' => 1, 'recorded_at' => now()->subDays(8)]);
        CourierLocation::query()->create(['store_id' => $this->store->id, 'user_id' => $this->courier->id, 'latitude' => 2, 'longitude' => 2, 'recorded_at' => now()->subDay()]);

        $this->artisan('app:prune-courier-locations')->assertSuccessful();

        $this->assertSame([2.0], CourierLocation::query()->pluck('latitude')->all());
    }

    private function member(StoreRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->store->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    /**
     * @return TestResponse<Response>
     */
    private function location(float $latitude = -23.5505): TestResponse
    {
        return $this->actingAs($this->courier)->postJson(route('deliveries.location'), [
            'latitude' => $latitude,
            'longitude' => -46.6333,
            'accuracy' => 12.4,
        ]);
    }

    private function deliveryOrder(): Order
    {
        return $this->onlineOrder([
            'fulfillment' => 'delivery',
            'delivery_zone_id' => $this->zone->id,
            'address' => ['street' => 'Rua das Flores', 'number' => '120'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function onlineOrder(array $overrides): Order
    {
        $this->post('/pizzaria-ze/orders', array_merge([
            'customer' => ['name' => 'Maria', 'phone' => '11999998888'],
            'payment_method' => 'pix',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ], $overrides))->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }
}
