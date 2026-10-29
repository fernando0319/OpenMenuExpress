<?php

namespace Tests\Feature\Orders;

use App\Contracts\MessagingProvider;
use App\Enums\AutoPrint;
use App\Enums\CashMovementType;
use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use App\Enums\StoreRole;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tab;
use App\Models\User;
use App\Printing\OrderChangeTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class EditOrderTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $burger;

    private Product $soda;

    private User $attendant;

    /** @var list<array{phone: string, text: string}> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MessagingProvider::class, new class($this) implements MessagingProvider
        {
            public function __construct(private readonly EditOrderTest $test) {}

            public function sendText(Store $store, string $phone, string $text): void
            {
                $this->test->sent[] = ['phone' => $phone, 'text' => $text];
            }

            public function isConfiguredFor(Store $store): bool
            {
                return true;
            }
        });

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze']);
        $category = Category::factory()->for($this->store)->create();
        $this->burger = Product::factory()->for($category)->create(['name' => 'X-Burger', 'price_cents' => 3000, 'track_stock' => true, 'stock_quantity' => 10]);
        $this->soda = Product::factory()->for($category)->create(['name' => 'Coca-Cola', 'price_cents' => 800]);
        $this->attendant = $this->member(StoreRole::Attendant);
    }

    public function test_items_are_changed_with_their_original_price_and_the_stock_follows(): void
    {
        $order = $this->deliveryOrder(burgers: 2); // 2x 30,00 + taxa 5,00
        $burgerItem = $order->items->sole();
        $this->burger->update(['price_cents' => 3500]); // price changed after the sale
        $this->assertSame(8, $this->burger->fresh()?->stock_quantity);

        $this->edit($order, [
            'items' => [
                ['id' => $burgerItem->id, 'quantity' => 3, 'notes' => 'sem cebola'],
                ['product_id' => $this->soda->id, 'quantity' => 2],
            ],
            'reason' => 'Cliente ligou pedindo mais um',
        ])->assertSessionHasNoErrors()->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertSame(3 * 3000 + 2 * 800, $order->subtotal_cents); // kept item keeps 30,00
        $this->assertSame(9000 + 1600 + 500, $order->total_cents);
        $this->assertSame(7, $this->burger->fresh()?->stock_quantity);
        $this->assertSame('sem cebola', $burgerItem->fresh()?->notes);

        $change = OrderChange::query()->sole();
        $this->assertSame($this->attendant->id, $change->user_id);
        $this->assertSame('Cliente ligou pedindo mais um', $change->reason);
        $this->assertSame(6500, $change->total_before_cents);
        $this->assertSame(11100, $change->total_after_cents);
        $this->assertSame(['+1 X-Burger', 'X-Burger: obs. "sem cebola"', '+2 Coca-Cola'], $change->itemLines());

        // Removing gives the units back.
        $this->edit($order, ['items' => [['product_id' => $this->soda->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->assertSame(10, $this->burger->fresh()?->stock_quantity);
        $this->assertSame(['−3 X-Burger', '−2 Coca-Cola', '+1 Coca-Cola'], OrderChange::query()->latest('id')->firstOrFail()->itemLines());
    }

    public function test_new_items_follow_the_option_rules(): void
    {
        $order = $this->deliveryOrder();
        $pizza = Product::factory()->for($this->burger->category)->create(['name' => 'Pizza', 'price_cents' => 1000]);
        $flavors = OptionGroup::factory()->for($this->store)->pizzaFlavors(PricingMode::Highest)->create();
        $calabresa = Option::factory()->for($flavors, 'group')->create(['name' => 'Calabresa', 'price_cents' => 4000]);
        $pizza->optionGroups()->attach($flavors);

        $this->edit($order, ['items' => [
            ['id' => $order->items->sole()->id, 'quantity' => 1],
            ['product_id' => $pizza->id, 'quantity' => 1],
        ]])->assertSessionHasErrors('items.1.options');

        $this->edit($order, ['items' => [
            ['id' => $order->items->sole()->id, 'quantity' => 1],
            ['product_id' => $pizza->id, 'quantity' => 1, 'options' => [['option_id' => $calabresa->id]]],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(3000 + 5000 + 500, $order->fresh()?->total_cents);
        $this->assertSame(['+1 Pizza (Calabresa)'], OrderChange::query()->sole()->itemLines());
    }

    public function test_a_paid_order_that_grows_has_the_difference_to_receive(): void
    {
        $this->openCash();
        $order = $this->paidCounterOrder(); // 30,00 paid in PIX

        $this->edit($order, ['items' => [
            ['id' => $order->items->sole()->id, 'quantity' => 1],
            ['product_id' => $this->soda->id, 'quantity' => 1],
        ]])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNull($order->paid_at);

        $this->actingAs($this->attendant)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.balanceCents', 800)
                ->where('order.canReceive', true)
                ->has('order.changes', 1));

        $this->actingAs($this->attendant)
            ->post(route('orders.payment.store', $order), ['payments' => [['method' => 'cash', 'amount' => '8,00']]])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($order->refresh()->paid_at);
        $this->assertSame(3800, (int) CashMovement::query()->where('order_id', $order->id)->sum('amount_cents'));
    }

    public function test_a_paid_order_that_shrinks_can_refund_the_difference(): void
    {
        $this->openCash();
        $order = $this->paidCounterOrder(burgers: 2, discount: ''); // 60,00 paid in PIX

        $this->edit($order, [
            'items' => [['id' => $order->items->sole()->id, 'quantity' => 1]],
            'refund_difference' => true,
        ])->assertSessionHasNoErrors();

        $refund = CashMovement::query()->where('type', CashMovementType::Refund)->sole();
        $this->assertSame(3000, $refund->amount_cents);
        $this->assertSame('pix', $refund->payment_method?->value);
        $this->assertSame(3000, OrderChange::query()->sole()->refunded_cents);
        $this->assertNotNull($order->refresh()->paid_at);
    }

    public function test_finished_orders_cannot_be_edited_and_couriers_cannot_edit(): void
    {
        $order = $this->deliveryOrder();
        $order->update(['status' => OrderStatus::Completed]);

        $this->actingAs($this->attendant)->get(route('orders.edit', $order))->assertRedirect(route('orders.show', $order));
        $this->edit($order, ['items' => [['id' => $order->items->sole()->id, 'quantity' => 2]]])->assertSessionHasErrors('items');

        $order->update(['status' => OrderStatus::Accepted]);
        $courier = $this->member(StoreRole::Courier);
        $this->actingAs($courier)->get(route('orders.edit', $order))->assertForbidden();

        $other = Store::factory()->create();
        $foreign = Order::query()->create([
            'store_id' => $other->id, 'number' => 1, 'customer_name' => 'X', 'channel' => 'online', 'fulfillment' => 'pickup',
            'status' => OrderStatus::Accepted, 'subtotal_cents' => 100, 'discount_cents' => 0, 'delivery_fee_cents' => 0,
            'total_cents' => 100, 'payment_method' => 'pix', 'placed_at' => now(),
        ]);
        $this->actingAs($this->attendant)->get(route('orders.edit', $foreign))->assertNotFound();
    }

    public function test_nothing_changed_is_refused_and_an_empty_order_must_be_cancelled_instead(): void
    {
        $order = $this->deliveryOrder();
        $item = $order->items->sole();

        $this->edit($order, ['items' => [['id' => $item->id, 'quantity' => 1]]])->assertSessionHasErrors(['items' => 'Nada foi alterado.']);
        $this->edit($order, ['items' => []])->assertSessionHasErrors('items');
    }

    public function test_the_kitchen_gets_only_the_difference_and_the_customer_may_be_told(): void
    {
        $this->store->update(['auto_print' => AutoPrint::WhenPlaced, 'whatsapp_enabled' => true]);
        $agent = $this->store->printAgents()->create(['name' => 'Caixa', 'token_hash' => PrintAgent::hashToken('t')]);
        $agent->printers()->create(['store_id' => $this->store->id, 'system_name' => 'EPSON', 'label' => 'Cozinha', 'paper_width' => 80, 'copies' => 2]);

        $order = $this->deliveryOrder();
        $this->sent = [];
        $jobsBefore = PrintJob::query()->count();

        $this->edit($order, [
            'items' => [['id' => $order->items->sole()->id, 'quantity' => 1], ['product_id' => $this->soda->id, 'quantity' => 1]],
            'notify_customer' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, PrintJob::query()->count() - $jobsBefore); // one change ticket, not the whole order again
        $ticket = (string) base64_decode(PrintJob::query()->latest('id')->firstOrFail()->payload, true);
        $this->assertStringContainsString('ALTERADO', $ticket);
        $this->assertStringContainsString('+ 1x Coca-Cola', $ticket);
        $this->assertStringNotContainsString('X-Burger', $ticket);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('foi atualizado', $this->sent[0]['text']);
        $this->assertStringContainsString('R$ 43,00', $this->sent[0]['text']);

        // A change of address alone does not bother the kitchen.
        $this->edit($order, [
            'items' => $order->items()->get()->map(fn ($item) => ['id' => $item->id, 'quantity' => 1])->all(),
            'address' => ['street' => 'Rua Nova', 'number' => '99'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, PrintJob::query()->count() - $jobsBefore);
        $this->assertSame(['Endereço: Rua Nova, 99'], OrderChange::query()->latest('id')->firstOrFail()->fields);
        $this->assertNotEmpty(app(OrderChangeTicket::class)->render($order->fresh() ?? $order, OrderChange::query()->latest('id')->firstOrFail(), 48));
    }

    public function test_a_waiter_removes_an_item_from_a_table_round(): void
    {
        $waiter = $this->member(StoreRole::Waiter);
        $table = $this->store->diningTables()->create(['name' => 'Mesa 1']);
        $this->actingAs($waiter)->post(route('tables.open', $table))->assertSessionHasNoErrors();
        $tab = Tab::query()->sole();
        $this->actingAs($waiter)->post(route('tables.rounds.store', $tab), [
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1], ['product_id' => $this->soda->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $round = Order::query()->sole();
        $soda = $round->items()->where('product_id', $this->soda->id)->sole();

        $this->actingAs($waiter)
            ->put(route('orders.update', $round), [
                'items' => [['id' => $soda->id, 'quantity' => 1]],
                'reason' => 'Lançado na mesa errada',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tables.show', $table));

        $this->assertSame(800, $tab->consumedCents());
        $this->assertSame(10, $this->burger->fresh()?->stock_quantity);
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

    private function deliveryOrder(int $burgers = 1): Order
    {
        $this->actingAs($this->attendant)->post(route('pos.orders.store'), [
            'fulfillment' => 'delivery',
            'customer' => ['name' => 'Maria', 'phone' => '11999998888'],
            'address' => ['street' => 'Rua A', 'number' => '10'],
            'delivery_fee' => '5,00',
            'pay_later' => true,
            'payment_method' => 'pix',
            'items' => [['product_id' => $this->burger->id, 'quantity' => $burgers]],
        ])->assertSessionHasNoErrors();

        return Order::query()->with('items')->latest('id')->firstOrFail();
    }

    private function paidCounterOrder(int $burgers = 1, string $discount = ''): Order
    {
        $this->actingAs($this->attendant)->post(route('pos.orders.store'), [
            'fulfillment' => 'pickup',
            'customer' => ['name' => '', 'phone' => ''],
            'discount' => $discount,
            'payments' => [['method' => 'pix', 'amount' => (string) (30 * $burgers)]],
            'items' => [['product_id' => $this->burger->id, 'quantity' => $burgers]],
        ])->assertSessionHasNoErrors();

        return Order::query()->with('items')->latest('id')->firstOrFail();
    }

    /**
     * Sends the edit form with the order's current data, changed by $overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function edit(Order $order, array $overrides): TestResponse
    {
        $order->refresh();

        return $this->actingAs($this->attendant)->put(route('orders.update', $order), array_merge([
            'customer' => ['name' => $order->customer_name, 'phone' => $order->customer_phone],
            'address' => ['street' => $order->delivery_street, 'number' => $order->delivery_number],
            'delivery_fee' => (string) ($order->delivery_fee_cents / 100),
            'discount' => (string) ($order->discount_cents / 100),
            'payment_method' => $order->tab_id === null && $order->paid_at === null ? $order->payment_method : null,
            'notes' => $order->notes,
        ], $overrides));
    }
}
