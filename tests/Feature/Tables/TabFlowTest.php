<?php

namespace Tests\Feature\Tables;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Enums\TabStatus;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tab;
use App\Models\User;
use App\Printing\TabBillTicket;
use App\Services\Tables\TabBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TabFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $beer;

    private User $waiter;

    private User $attendant;

    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['service_fee_percent' => 10]);
        $this->beer = Product::factory()->for(Category::factory()->for($this->store))->create(['name' => 'Chopp', 'price_cents' => 1250]);
        $this->waiter = $this->member(StoreRole::Waiter);
        $this->attendant = $this->member(StoreRole::Attendant);
        $this->table = $this->store->diningTables()->create(['name' => 'Mesa 1']);
    }

    public function test_a_table_from_opening_to_paid_bill(): void
    {
        $this->actingAs($this->waiter)->post(route('tables.open', $this->table), ['people' => 2])->assertSessionHasNoErrors();
        $tab = Tab::query()->sole();

        $this->round($tab, 2)->assertSessionHasNoErrors();
        $this->round($tab, 1)->assertSessionHasNoErrors();

        $rounds = Order::query()->orderBy('id')->get();
        $this->assertCount(2, $rounds);
        $this->assertSame(OrderChannel::Waiter, $rounds[0]->channel);
        $this->assertSame(OrderStatus::Accepted, $rounds[0]->status);
        $this->assertSame('Mesa 1', $rounds[0]->customer_name);
        $this->assertSame([1, 2], $rounds->pluck('number')->all());

        $this->actingAs($this->waiter)->post(route('tables.bill', $tab))->assertSessionHasNoErrors();
        $this->assertSame(TabStatus::Closing, $tab->fresh()?->status);

        // More drinks after asking for the bill: the table is consuming again.
        $this->round($tab, 1)->assertSessionHasNoErrors();
        $this->assertSame(TabStatus::Open, $tab->fresh()?->status);

        $this->actingAs($this->attendant)->post(route('cash.open'), ['opening' => '0']);

        // 4 × 12,50 = 50,00 + 10% = 55,00
        $this->actingAs($this->waiter)->post(route('tables.close', $tab), ['payments' => [['method' => 'pix', 'amount' => '55']]])
            ->assertForbidden();

        $this->actingAs($this->attendant)
            ->post(route('tables.close', $tab), [
                'service_fee' => true,
                'payments' => [['method' => 'pix', 'amount' => '30'], ['method' => 'cash', 'amount' => '30']],
            ])
            ->assertRedirect(route('tables.index'))
            ->assertSessionHasNoErrors();

        $tab->refresh();
        $this->assertSame(TabStatus::Closed, $tab->status);
        $this->assertSame(5000, $tab->subtotal_cents);
        $this->assertSame(500, $tab->service_fee_cents);
        $this->assertSame(5500, $tab->total_cents);
        $this->assertSame(Order::PAYMENT_SPLIT, $tab->payment_method);

        $this->assertSame(5500, (int) CashMovement::query()->sum('amount_cents'));
        $this->assertSame(2500, CashMovement::query()->where('payment_method', 'cash')->sole()->amount_cents);
        $this->assertTrue(CashMovement::query()->get()->every(fn (CashMovement $movement) => $movement->tab_id === $tab->id && $movement->order_id === null));

        foreach (Order::query()->get() as $round) {
            $this->assertSame(OrderStatus::Completed, $round->status);
            $this->assertNotNull($round->paid_at);
        }

        // Completing the rounds did not count them again in the cash.
        $this->assertSame(2, CashMovement::query()->count());

        $this->actingAs($this->attendant)
            ->get(route('cash.index'))
            ->assertInertia(fn (Assert $page) => $page->where('session.summary.ordersCount', 1));

        $this->actingAs($this->waiter)
            ->get(route('tables.index'))
            ->assertInertia(fn (Assert $page) => $page->component('tables/Index')->where('tables.0.status', 'free'));
    }

    public function test_service_fee_is_optional_and_cancelled_rounds_are_not_charged(): void
    {
        $tab = $this->openTab();
        $this->round($tab, 2);
        $this->round($tab, 1);

        $cancelled = Order::query()->orderByDesc('id')->firstOrFail();
        $this->actingAs($this->attendant)->patch(route('orders.status.update', $cancelled), ['status' => 'cancelled']);

        $this->actingAs($this->attendant)->post(route('cash.open'), ['opening' => '0']);

        $this->actingAs($this->attendant)
            ->post(route('tables.close', $tab), ['service_fee' => false, 'discount' => '5', 'payments' => [['method' => 'pix', 'amount' => '19']]])
            ->assertSessionHasErrors('payments');

        $this->actingAs($this->attendant)
            ->post(route('tables.close', $tab), ['service_fee' => false, 'discount' => '5', 'payments' => [['method' => 'pix', 'amount' => '20']]])
            ->assertSessionHasNoErrors();

        $tab->refresh();
        $this->assertSame(2500, $tab->subtotal_cents);
        $this->assertSame(0, $tab->service_fee_cents);
        $this->assertSame(2000, $tab->total_cents);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()?->status);
    }

    public function test_closing_needs_an_open_cash_session(): void
    {
        $tab = $this->openTab();
        $this->round($tab, 1);

        $this->actingAs($this->attendant)
            ->post(route('tables.close', $tab), ['payments' => [['method' => 'pix', 'amount' => '13,75']]])
            ->assertSessionHasErrors('cash');

        $this->assertSame(TabStatus::Open, $tab->fresh()?->status);
    }

    public function test_occupied_tables_cannot_be_opened_twice_and_tabs_can_move(): void
    {
        $tab = $this->openTab();
        $free = $this->store->diningTables()->create(['name' => 'Mesa 2']);
        $busy = $this->store->diningTables()->create(['name' => 'Mesa 3']);
        $this->actingAs($this->waiter)->post(route('tables.open', $busy));

        $this->actingAs($this->waiter)->post(route('tables.open', $this->table))->assertSessionHasErrors('table');

        $this->actingAs($this->waiter)->post(route('tables.transfer', $tab), ['table_id' => $busy->id])->assertSessionHasErrors('table');
        $this->actingAs($this->waiter)->post(route('tables.transfer', $tab), ['table_id' => $free->id])
            ->assertRedirect(route('tables.show', $free));

        $this->assertSame($free->id, $tab->fresh()?->dining_table_id);
        $this->actingAs($this->waiter)->post(route('tables.open', $this->table))->assertSessionHasNoErrors();
    }

    public function test_a_table_is_released_only_without_consumption(): void
    {
        $tab = $this->openTab();
        $this->round($tab, 1);

        $this->actingAs($this->waiter)->post(route('tables.release', $tab))->assertSessionHasErrors('tab');

        $this->actingAs($this->attendant)->patch(route('orders.status.update', Order::query()->sole()), ['status' => 'cancelled']);

        $this->actingAs($this->waiter)->post(route('tables.release', $tab))->assertRedirect(route('tables.index'));
        $this->assertSame(TabStatus::Cancelled, $tab->fresh()?->status);
    }

    public function test_table_rounds_are_paid_with_the_bill_not_on_their_own(): void
    {
        $tab = $this->openTab();
        $this->round($tab, 1);
        $this->actingAs($this->attendant)->post(route('cash.open'), ['opening' => '0']);
        $round = Order::query()->sole();

        $this->actingAs($this->attendant)
            ->get(route('orders.show', $round))
            ->assertInertia(fn (Assert $page) => $page->where('order.canReceive', false)->where('order.paymentMethod', 'Na conta da mesa'));

        $this->actingAs($this->attendant)
            ->post(route('orders.payment.store', $round), ['payments' => [['method' => 'pix', 'amount' => '12,50']]])
            ->assertSessionHasErrors('payments');

        // Completing a round in the kitchen flow does not charge it either.
        $this->actingAs($this->attendant)->patch(route('orders.status.update', $round), ['status' => 'completed']);
        $this->assertSame(0, CashMovement::query()->count());
    }

    public function test_bill_request_prints_the_check(): void
    {
        $agent = $this->store->printAgents()->create(['name' => 'Caixa', 'token_hash' => PrintAgent::hashToken('x')]);
        $agent->printers()->create(['store_id' => $this->store->id, 'system_name' => 'POS-58', 'label' => 'Balcão', 'paper_width' => 58]);

        $tab = $this->openTab(people: 3);
        $this->round($tab, 2);
        PrintJob::query()->delete(); // kitchen ticket of the round

        $this->actingAs($this->waiter)->post(route('tables.bill', $tab))->assertSessionHasNoErrors();
        $this->assertSame('bill', PrintJob::query()->sole()->kind);

        $text = (string) iconv('CP850', 'UTF-8', (new TabBillTicket)->render($tab, TabBill::for($tab), 32));
        $this->assertStringContainsString('MESA 1', $text);
        $this->assertStringContainsString('Serviço (10%)', $text);
        $this->assertStringContainsString('R$ 27,50', $text);
        $this->assertStringContainsString('Por pessoa (3)', $text);

        foreach (explode("\n", $text) as $line) {
            $printable = preg_replace('/\x1B@|[\x1B\x1D]..|[\x00-\x1F]/', '', $line) ?? '';
            $this->assertLessThanOrEqual(32, mb_strlen($printable), "Line too long: {$printable}");
        }
    }

    public function test_table_screen_shows_the_tab(): void
    {
        $tab = $this->openTab();
        $this->round($tab, 2);

        $this->actingAs($this->waiter)
            ->get(route('tables.show', $this->table))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tables/Show')
                ->where('tab.bill.subtotalCents', 2500)
                ->where('tab.bill.serviceFeeCents', 250)
                ->where('tab.rounds.0.items.0.name', 'Chopp')
                ->where('canClose', false)
                ->where('categories.0.products.0.name', 'Chopp'));
    }

    public function test_waiters_land_on_the_tables_and_stay_out_of_the_counter(): void
    {
        $this->actingAs($this->waiter)->get(route('dashboard'))->assertRedirect(route('tables.index'));
        $this->actingAs($this->attendant)->get(route('dashboard'))->assertOk();

        $this->actingAs($this->waiter)->get(route('pos.show'))->assertForbidden();
        $this->actingAs($this->waiter)->get(route('store-settings.tables.edit'))->assertForbidden();
    }

    public function test_tables_of_other_stores_are_hidden(): void
    {
        $other = Store::factory()->create()->diningTables()->create(['name' => 'Mesa 1']);

        $this->actingAs($this->waiter)->get(route('tables.show', $other))->assertNotFound();
        $this->actingAs($this->waiter)->post(route('tables.open', $other))->assertNotFound();
    }

    private function member(StoreRole $role): User
    {
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role' => $role->value]);

        return $user;
    }

    private function openTab(?int $people = null): Tab
    {
        $this->actingAs($this->waiter)->post(route('tables.open', $this->table), ['people' => $people])->assertSessionHasNoErrors();

        return Tab::query()->latest('id')->firstOrFail();
    }

    /**
     * @return TestResponse<Response>
     */
    private function round(Tab $tab, int $quantity): TestResponse
    {
        return $this->actingAs($this->waiter)->post(route('tables.rounds.store', $tab), [
            'items' => [['product_id' => $this->beer->id, 'quantity' => $quantity]],
        ]);
    }
}
