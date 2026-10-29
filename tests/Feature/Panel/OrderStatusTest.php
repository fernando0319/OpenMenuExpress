<?php

namespace Tests\Feature\Panel;

use App\Enums\Fulfillment;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->attendant = User::factory()->create();
        $this->store->users()->attach($this->attendant, ['role' => StoreRole::Attendant->value]);
    }

    public function test_next_statuses_depend_on_fulfillment(): void
    {
        $this->assertSame(
            [OrderStatus::Accepted, OrderStatus::Cancelled],
            OrderStatus::Pending->nextFor(Fulfillment::Delivery),
        );
        $this->assertContains(OrderStatus::OutForDelivery, OrderStatus::Accepted->nextFor(Fulfillment::Delivery));
        $this->assertNotContains(OrderStatus::ReadyForPickup, OrderStatus::Accepted->nextFor(Fulfillment::Delivery));
        $this->assertContains(OrderStatus::ReadyForPickup, OrderStatus::Ready->nextFor(Fulfillment::Pickup));
        $this->assertSame([], OrderStatus::Completed->nextFor(Fulfillment::Pickup));
    }

    public function test_attendant_moves_an_order_forward_and_history_is_kept(): void
    {
        $order = $this->makeOrder(Fulfillment::Delivery);

        $this->actingAs($this->attendant)
            ->patch(route('orders.status.update', $order), ['status' => 'accepted'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->attendant)
            ->patch(route('orders.status.update', $order), ['status' => 'out_for_delivery'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::OutForDelivery, $order->status);
        $this->assertSame(
            [OrderStatus::Accepted, OrderStatus::OutForDelivery],
            $order->statusHistory->pluck('to_status')->all(),
        );
        $this->assertSame($this->attendant->id, $order->statusHistory->last()?->user_id);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $order = $this->makeOrder(Fulfillment::Pickup);

        $this->actingAs($this->attendant)
            ->patch(route('orders.status.update', $order), ['status' => 'out_for_delivery'])
            ->assertSessionHasErrors('status');

        $this->actingAs($this->attendant)
            ->patch(route('orders.status.update', $order), ['status' => 'completed'])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::Pending, $order->fresh()?->status);
    }

    public function test_cancelling_returns_stock(): void
    {
        $product = Product::factory()
            ->for(Category::factory()->for($this->store))
            ->create(['track_stock' => true, 'stock_quantity' => 5]);

        $order = $this->makeOrder(Fulfillment::Pickup);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price_cents' => 1000,
            'quantity' => 2,
            'total_cents' => 2000,
        ]);

        $this->actingAs($this->attendant)
            ->patch(route('orders.status.update', $order), ['status' => 'cancelled'])
            ->assertSessionHasNoErrors();

        $this->assertSame(7, $product->fresh()?->stock_quantity);
    }

    public function test_orders_of_another_store_cannot_be_changed(): void
    {
        $foreign = $this->makeOrder(Fulfillment::Pickup, Store::factory()->create());

        $this->actingAs($this->attendant)
            ->patch(route('orders.status.update', $foreign), ['status' => 'accepted'])
            ->assertNotFound();
    }

    private function makeOrder(Fulfillment $fulfillment, ?Store $store = null): Order
    {
        $store ??= $this->store;

        return $store->orders()->create([
            'number' => (int) $store->orders()->max('number') + 1,
            'customer_name' => 'Maria Silva',
            'customer_phone' => '5511999998888',
            'channel' => OrderChannel::Online,
            'fulfillment' => $fulfillment,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 2000,
            'total_cents' => 2000,
            'payment_method' => 'pix',
            'placed_at' => now(),
        ]);
    }
}
