<?php

namespace Tests\Feature\Orders;

use App\Enums\Fulfillment;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PendingOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_polls_the_orders_waiting_to_be_accepted(): void
    {
        $store = Store::factory()->create();
        $user = $this->memberOf($store, StoreRole::Attendant);

        $first = $this->order($store, OrderStatus::Pending, minutesAgo: 5);
        $this->order($store, OrderStatus::Pending);
        $this->order($store, OrderStatus::Accepted);
        $this->order($store, OrderStatus::PendingPayment);
        $this->order(Store::factory()->create(), OrderStatus::Pending);

        $this->actingAs($user)->withSession(['current_store_id' => $store->id])
            ->getJson(route('orders.pending'))
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('oldest.id', $first->id)
            ->assertJsonPath('oldest.number', $first->number);
    }

    public function test_couriers_do_not_get_the_alert(): void
    {
        $store = Store::factory()->create();
        $courier = $this->memberOf($store, StoreRole::Courier);

        $this->actingAs($courier)->withSession(['current_store_id' => $store->id])
            ->getJson(route('orders.pending'))
            ->assertForbidden();
    }

    public function test_the_open_close_switch_pauses_the_menu(): void
    {
        $store = Store::factory()->create(['is_paused' => false]);
        $user = $this->memberOf($store, StoreRole::Attendant);

        $this->actingAs($user)->withSession(['current_store_id' => $store->id])
            ->put(route('store-status.update'), ['open' => false])
            ->assertRedirect();
        $this->assertTrue($store->fresh()?->is_paused);

        $this->actingAs($user)->withSession(['current_store_id' => $store->id])
            ->put(route('store-status.update'), ['open' => true]);
        $this->assertFalse($store->fresh()?->is_paused);

        // Waiters serve tables but do not open or close the store.
        $waiter = $this->memberOf($store, StoreRole::Waiter);
        $this->actingAs($waiter)->withSession(['current_store_id' => $store->id])
            ->put(route('store-status.update'), ['open' => false])
            ->assertForbidden();
    }

    private function order(Store $store, OrderStatus $status, int $minutesAgo = 0): Order
    {
        return $store->orders()->create([
            'number' => (int) $store->orders()->max('number') + 1,
            'public_token' => Str::random(32),
            'customer_name' => 'Maria',
            'customer_phone' => '5511999998888',
            'channel' => OrderChannel::Online,
            'fulfillment' => Fulfillment::Pickup,
            'status' => $status,
            'subtotal_cents' => 1000,
            'total_cents' => 1000,
            'payment_method' => 'pix',
            'placed_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function memberOf(Store $store, StoreRole $role): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
