<?php

namespace Tests\Feature\Pos;

use App\Enums\CashMovementType;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Models\CashMovement;
use App\Models\Category;
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

class PosSaleTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze']);
        $this->product = Product::factory()->for(Category::factory()->for($this->store))->create([
            'name' => 'X-Burger',
            'price_cents' => 5000,
            'track_stock' => true,
            'stock_quantity' => 10,
        ]);
        $this->attendant = $this->member(StoreRole::Attendant);
    }

    public function test_counter_sale_with_split_payment_discount_and_change(): void
    {
        $this->openCash();

        $this->sell([
            'discount' => '5,00',
            'complete_now' => true,
            'payments' => [
                ['method' => 'pix', 'amount' => '50'],
                ['method' => 'cash', 'amount' => '50,00'],
            ],
        ], quantity: 2)->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(OrderChannel::Pos, $order->channel);
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame('Cliente balcão', $order->customer_name);
        $this->assertNull($order->customer_id);
        $this->assertSame(9500, $order->total_cents);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(Order::PAYMENT_SPLIT, $order->payment_method);
        $this->assertSame(5000, $order->change_for_cents);
        $this->assertSame(8, $this->product->fresh()?->stock_quantity);

        $movements = CashMovement::query()->orderBy('id')->get();
        $this->assertSame([['pix', 5000], ['cash', 4500]], $movements->map(fn (CashMovement $m) => [$m->payment_method?->value, $m->amount_cents])->all());
        $this->assertSame($this->attendant->id, $order->statusHistory()->sole()->user_id);
    }

    public function test_selling_needs_an_open_cash_session(): void
    {
        $this->sell(['payments' => [['method' => 'pix', 'amount' => '50']]])
            ->assertSessionHasErrors('cash');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_payment_must_cover_the_total_and_only_cash_gives_change(): void
    {
        $this->openCash();

        $this->sell(['payments' => [['method' => 'pix', 'amount' => '40']]])
            ->assertSessionHasErrors(['payments' => 'Faltam R$ 10,00 para fechar o pagamento.']);

        $this->sell(['payments' => [['method' => 'credit_card', 'amount' => '60']]])
            ->assertSessionHasErrors('payments');

        $this->sell(['discount' => '70', 'payments' => [['method' => 'pix', 'amount' => '1']]])
            ->assertSessionHasErrors('discount');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(10, $this->product->fresh()?->stock_quantity);
    }

    public function test_delivery_paid_on_arrival_becomes_a_sale_when_completed(): void
    {
        $this->openCash();
        $zone = DeliveryZone::query()->create(['store_id' => $this->store->id, 'name' => 'Centro', 'fee_cents' => 700]);

        $this->sell(['fulfillment' => 'delivery', 'pay_later' => true, 'payment_method' => 'cash'])
            ->assertSessionHasErrors('customer.name');

        $this->sell([
            'fulfillment' => 'delivery',
            'customer' => ['name' => 'Maria', 'phone' => '(11) 99999-8888'],
            'address' => ['street' => 'Rua A', 'number' => '10'],
            'delivery_zone_id' => $zone->id,
            'pay_later' => true,
            'payment_method' => 'cash',
            'change_for' => '100',
        ])->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(OrderStatus::Accepted, $order->status);
        $this->assertSame(5700, $order->total_cents);
        $this->assertSame('Centro', $order->delivery_district);
        $this->assertSame('5511999998888', $order->customer_phone);
        $this->assertNull($order->paid_at);
        $this->assertSame(0, CashMovement::query()->count());

        $this->actingAs($this->attendant)->patch(route('orders.status.update', $order), ['status' => 'out_for_delivery']);
        $this->actingAs($this->attendant)->patch(route('orders.status.update', $order), ['status' => 'completed']);

        $movement = CashMovement::query()->sole();
        $this->assertSame(CashMovementType::Sale, $movement->type);
        $this->assertSame(5700, $movement->amount_cents);
        $this->assertNotNull($order->fresh()?->paid_at);
    }

    public function test_cancelling_a_paid_order_refunds_it_in_the_cash(): void
    {
        $this->openCash();
        $this->sell(['payments' => [['method' => 'debit_card', 'amount' => '50']]])->assertSessionHasNoErrors();
        $order = Order::query()->sole();

        $this->actingAs($this->attendant)->patch(route('orders.status.update', $order), ['status' => 'cancelled']);

        $refund = CashMovement::query()->where('type', CashMovementType::Refund)->sole();
        $this->assertSame(5000, $refund->amount_cents);
        $this->assertSame('debit_card', $refund->payment_method?->value);
        $this->assertSame(10, $this->product->fresh()?->stock_quantity);
    }

    public function test_an_unpaid_order_can_be_received_from_its_page(): void
    {
        $order = $this->onlineOrder();

        $this->actingAs($this->attendant)
            ->post(route('orders.payment.store', $order), ['payments' => [['method' => 'cash', 'amount' => '50']]])
            ->assertSessionHasErrors('cash');

        $this->openCash();

        $this->actingAs($this->attendant)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->where('order.canReceive', true));

        $this->actingAs($this->attendant)
            ->post(route('orders.payment.store', $order), ['payments' => [['method' => 'cash', 'amount' => '60']]])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertSame(6000, $order->change_for_cents);
        $this->assertSame(5000, CashMovement::query()->sole()->amount_cents);

        $this->actingAs($this->attendant)
            ->post(route('orders.payment.store', $order), ['payments' => [['method' => 'cash', 'amount' => '50']]])
            ->assertSessionHasErrors('payments');
    }

    public function test_pos_page_lists_the_catalog(): void
    {
        $this->actingAs($this->attendant)
            ->get(route('pos.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pos/Index')
                ->where('cashOpen', false)
                ->where('categories.0.products.0.name', 'X-Burger'));
    }

    public function test_waiters_cannot_use_the_pos_or_the_cash(): void
    {
        $waiter = $this->member(StoreRole::Waiter);

        $this->actingAs($waiter)->get(route('pos.show'))->assertForbidden();
        $this->actingAs($waiter)->get(route('cash.index'))->assertForbidden();
        $this->actingAs($waiter)->post(route('cash.open'), ['opening' => '0'])->assertForbidden();
    }

    private function member(StoreRole $role): User
    {
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function openCash(): void
    {
        $this->actingAs($this->attendant)->post(route('cash.open'), ['opening' => '100'])->assertSessionHasNoErrors();
    }

    private function onlineOrder(): Order
    {
        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'João', 'phone' => '11988887777'],
            'payment_method' => 'pix',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        return Order::query()->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function sell(array $overrides = [], int $quantity = 1): TestResponse
    {
        return $this->actingAs($this->attendant)->post(route('pos.orders.store'), array_merge([
            'fulfillment' => 'pickup',
            'customer' => ['name' => '', 'phone' => ''],
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ], $overrides));
    }
}
