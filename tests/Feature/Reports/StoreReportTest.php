<?php

namespace Tests\Feature\Reports;

use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tab;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // 12:00 in São Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'timezone' => 'America/Sao_Paulo', 'service_fee_percent' => 10]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
        $this->product = Product::factory()->for(Category::factory()->for($this->store))->create(['name' => 'Pizza', 'price_cents' => 5000]);
    }

    public function test_sales_of_every_channel_add_up(): void
    {
        $this->actingAs($this->owner)->post(route('cash.open'), ['opening' => '0']);

        // PDV counter sale, paid with PIX.
        $this->actingAs($this->owner)->post(route('pos.orders.store'), [
            'fulfillment' => 'pickup',
            'complete_now' => true,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'payments' => [['method' => 'pix', 'amount' => '50']],
        ])->assertSessionHasNoErrors();

        // Online order completed, paid on pickup by card.
        $online = $this->onlineOrder('credit_card');
        $this->actingAs($this->owner)->patch(route('orders.status.update', $online), ['status' => 'accepted']);
        $this->actingAs($this->owner)->patch(route('orders.status.update', $online), ['status' => 'completed']);

        // Cancelled online order: not a sale.
        $cancelled = $this->onlineOrder('pix');
        $this->actingAs($this->owner)->patch(route('orders.status.update', $cancelled), ['status' => 'cancelled']);

        // Table: one pizza + 10% service, paid in cash.
        $table = $this->store->diningTables()->create(['name' => 'Mesa 1']);
        $this->actingAs($this->owner)->post(route('tables.open', $table));
        $tab = Tab::query()->sole();
        $this->actingAs($this->owner)->post(route('tables.rounds.store', $tab), ['items' => [['product_id' => $this->product->id, 'quantity' => 1]]]);
        $this->actingAs($this->owner)->post(route('tables.close', $tab), ['payments' => [['method' => 'cash', 'amount' => '55']]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->get(route('reports.index', ['period' => 'today']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('reports/Index')
                ->where('period.label', '15/10/2026')
                ->where('report.totals.revenueCents', 15500)
                ->where('report.totals.salesCount', 3)
                ->where('report.totals.averageTicketCents', 5166)
                ->where('report.totals.cancelledCount', 1)
                ->where('report.totals.cancelledCents', 5000)
                ->where('report.totals.serviceFeeCents', 500)
                ->where('report.daily.0.revenueCents', 15500)
                ->where('report.hourly.12.salesCount', 3)
                ->where('report.channels.0.salesCount', 0) // delivery
                ->where('report.channels.1.salesCount', 2) // pickup / counter
                ->where('report.channels.2.revenueCents', 5500) // tables
                ->where('report.topProducts.0.name', 'Pizza')
                ->where('report.topProducts.0.quantity', 3)
                ->where('report.topCustomers.0.ordersCount', 1)
                ->where('report.payments', fn ($payments) => collect($payments)->pluck('cents', 'method')->only(['cash', 'pix', 'credit_card'])->all() === ['cash' => 5500, 'pix' => 5000, 'credit_card' => 5000]));
    }

    public function test_days_follow_the_store_timezone(): void
    {
        // 23:30 of the 14th in São Paulo is already the 15th in UTC.
        $order = $this->onlineOrder('pix');
        $order->forceFill(['status' => 'completed', 'placed_at' => CarbonImmutable::parse('2026-10-15 02:30:00', 'UTC')])->save();

        $this->actingAs($this->owner)
            ->get(route('reports.index', ['period' => 'yesterday']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.label', '14/10/2026')
                ->where('report.totals.salesCount', 1)
                ->where('report.hourly.23.salesCount', 1));

        $this->actingAs($this->owner)
            ->get(route('reports.index', ['period' => 'today']))
            ->assertInertia(fn (Assert $page) => $page->where('report.totals.salesCount', 0)->where('report.previous.salesCount', 1));
    }

    public function test_periods(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC');

        $week = ReportPeriod::from('7d', null, null, 'America/Sao_Paulo', $now);
        $this->assertSame('09/10/2026 a 15/10/2026', $week->label());
        $this->assertSame(7, $week->days());
        $this->assertSame('02/10/2026 a 08/10/2026', $week->previous()->label());
        $this->assertSame('2026-10-09 03:00:00', $week->startUtc()->toDateTimeString());

        $this->assertSame('01/09/2026 a 30/09/2026', ReportPeriod::from('last_month', null, null, 'America/Sao_Paulo', $now)->label());

        $this->actingAs($this->owner)
            ->get(route('reports.index', ['period' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('period');
    }

    public function test_orders_export_as_a_spreadsheet(): void
    {
        $this->onlineOrder('pix');

        $response = $this->actingAs($this->owner)
            ->get(route('reports.export', ['period' => 'today']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Número;Data;Origem', $csv);
        $this->assertStringContainsString('1;"15/10/2026 12:00";"Cardápio online";Retirada', $csv);
        $this->assertStringContainsString('50,00', $csv);
    }

    public function test_only_owners_and_managers_see_reports(): void
    {
        $attendant = User::factory()->create();
        $this->store->users()->attach($attendant, ['role' => StoreRole::Attendant->value]);

        $this->actingAs($attendant)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($attendant)->get(route('reports.export'))->assertForbidden();
    }

    private function onlineOrder(string $payment): Order
    {
        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '11999998888'],
            'payment_method' => $payment,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }
}
