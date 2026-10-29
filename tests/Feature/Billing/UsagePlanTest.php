<?php

namespace Tests\Feature\Billing;

use App\Enums\Module;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\Billing\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UsagePlanTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.enabled' => true, 'billing.grace_days' => 7, 'billing.warn_days' => 5]);
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'timezone' => 'America/Sao_Paulo', 'trial_ends_at' => CarbonImmutable::parse('2026-08-01 03:00:00', 'UTC')]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);

        // Free up to R$ 3.000 of online sales; above, 2% of what passes, min R$ 19, cap R$ 299.
        $this->plan = Plan::query()->create([
            'name' => 'Por uso',
            'price_cents' => 0,
            'interval' => 'month',
            'free_up_to_cents' => 300000,
            'percent_bps' => 200,
            'percent_base' => Plan::BASE_EXCESS,
            'fee_min_cents' => 1900,
            'fee_max_cents' => 29900,
            'modules' => Module::values(),
        ]);
    }

    public function test_the_fee_follows_the_limit_the_base_the_minimum_and_the_cap(): void
    {
        $this->assertSame(0, $this->plan->usageFee(300000));
        $this->assertSame(1900, $this->plan->usageFee(310000)); // 2% of R$ 100 = R$ 2, minimum R$ 19
        $this->assertSame(4000, $this->plan->usageFee(500000)); // 2% of R$ 2.000
        $this->assertSame(29900, $this->plan->usageFee(5000000)); // capped

        $this->plan->percent_base = Plan::BASE_TOTAL;
        $this->plan->fee_min_cents = null;
        $this->assertSame(0, $this->plan->usageFee(299999));
        $this->assertSame(6200, $this->plan->usageFee(310000)); // 2% of everything once above

        $this->assertSame(
            'Grátis até R$ 3.000,00 em vendas online no mês; acima, 2% das vendas online (máx. R$ 299,00).',
            $this->plan->usageSummary(),
        );
    }

    public function test_only_online_sales_count_and_an_unpaid_month_blocks_after_the_grace(): void
    {
        $this->store->update(['plan_id' => $this->plan->id]);
        $this->sale('online', 500000, '2026-09-10 20:00:00', deliveryFee: 1000); // R$ 4.990 of products
        $this->sale('pos', 900000, '2026-09-12 20:00:00'); // counter: not charged
        $this->sale('online', 800000, '2026-10-02 20:00:00'); // October: next invoice

        // Still in September's month (as seen from October 15th the invoice for September exists).
        $subscription = Subscription::for($this->store);
        $invoice = Invoice::query()->sole();
        $this->assertSame('2026-09-01', $invoice->period_start->format('Y-m-d'));
        $this->assertSame(499000, $invoice->sales_cents);
        $this->assertSame(3980, $invoice->amount_cents); // 2% of R$ 1.990
        $this->assertSame('setembro de 2026', $invoice->periodLabel());

        // Due on October 1st, 7 days of grace: blocked on the 15th.
        $this->assertSame(Subscription::EXPIRED, $subscription->status);
        $this->assertSame(Subscription::REASON_INVOICE, $subscription->reason);
        $this->assertSame('Fatura em aberto', $subscription->label());
        $this->actingAs($this->owner)->get(route('orders.index'))->assertRedirect(route('billing.show'));

        $this->travelTo(CarbonImmutable::parse('2026-10-05 15:00:00', 'UTC'));
        $this->assertSame(Subscription::GRACE, Subscription::for($this->store)->status);

        // The platform receives the payment.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->post(route('admin.invoices.settle', $invoice), ['status' => 'paid', 'note' => 'PIX recebido'])->assertSessionHasNoErrors();

        $subscription = Subscription::for($this->store->fresh() ?? $this->store);
        $this->assertSame(Subscription::USAGE, $subscription->status);
        $this->assertSame(800000, $subscription->usage()['salesCents'] ?? null);
        $this->assertSame(10000, $subscription->usage()['estimateCents'] ?? null); // 2% of R$ 5.000

        $this->actingAs($this->owner)->get(route('billing.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subscription.status', 'usage')
                ->where('invoices.0.statusLabel', 'Paga')
                ->where('invoices.0.amountCents', 3980));
    }

    public function test_below_the_limit_the_month_is_free_and_the_store_keeps_working(): void
    {
        $this->sale('online', 250000, '2026-09-10 20:00:00');

        // No plan on the store: the plan charged by use is the default one after the trial.
        $subscription = Subscription::for($this->store);

        $this->assertSame(Subscription::USAGE, $subscription->status);
        $this->assertSame('Por uso', $subscription->plan?->name);
        $this->assertSame(0, Invoice::query()->sole()->amount_cents);
        $this->assertSame('Sem cobrança', Invoice::query()->sole()->statusLabel());
        $this->actingAs($this->owner)->get(route('orders.index'))->assertOk();
    }

    public function test_the_trial_part_of_the_month_is_not_charged(): void
    {
        $this->store->update(['trial_ends_at' => CarbonImmutable::parse('2026-09-15 03:00:00', 'UTC')]);
        $this->sale('online', 900000, '2026-09-10 20:00:00'); // during the trial
        $this->sale('online', 350000, '2026-09-20 20:00:00');

        Subscription::for($this->store);

        $this->assertSame(350000, Invoice::query()->sole()->sales_cents);
        $this->assertSame(1900, Invoice::query()->sole()->amount_cents);
    }

    public function test_the_admin_sets_up_a_plan_charged_by_use_and_follows_the_invoices(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->post(route('admin.plans.store'), [
            'name' => 'Comissão', 'price' => '0', 'interval' => 'year', 'free_up_to' => '2.000,00',
            'percent' => '1,5', 'percent_base' => 'total', 'fee_min' => '', 'fee_max' => '199,00', 'modules' => [],
        ])->assertSessionHasNoErrors();

        $plan = Plan::query()->where('name', 'Comissão')->sole();
        $this->assertSame(150, $plan->percent_bps);
        $this->assertSame('total', $plan->percent_base);
        $this->assertSame('month', $plan->interval);
        $this->assertSame(19900, $plan->fee_max_cents);

        $this->actingAs($admin)->post(route('admin.plans.store'), [
            'name' => 'X', 'price' => '0', 'interval' => 'month', 'percent' => '80', 'modules' => [],
        ])->assertSessionHasErrors('percent');

        $this->store->update(['plan_id' => $this->plan->id]);
        $this->sale('online', 500000, '2026-09-10 20:00:00');
        $this->artisan('app:close-invoices')->assertSuccessful();

        $this->actingAs($admin)->get(route('admin.invoices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Invoices')
                ->has('invoices.data', 1)
                ->where('invoices.data.0.storeName', $this->store->name)
                ->where('invoices.data.0.overdue', true)
                ->where('dueTotalCents', 4000));

        $invoice = Invoice::query()->sole();
        $this->actingAs($admin)->post(route('admin.invoices.settle', $invoice), ['status' => 'waived'])->assertSessionHasNoErrors();
        $this->assertSame(Invoice::WAIVED, $invoice->fresh()?->status);
        $this->actingAs($admin)->post(route('admin.invoices.reopen', $invoice))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::OPEN, $invoice->fresh()?->status);

        $this->actingAs($this->owner)->get(route('admin.invoices.index'))->assertForbidden();
    }

    private function sale(string $channel, int $cents, string $placedAtLocal, int $deliveryFee = 0): void
    {
        Order::query()->create([
            'store_id' => $this->store->id,
            'number' => (int) $this->store->orders()->max('number') + 1,
            'customer_name' => 'Cliente',
            'channel' => $channel,
            'fulfillment' => $deliveryFee > 0 ? 'delivery' : 'pickup',
            'status' => OrderStatus::Completed,
            'subtotal_cents' => $cents - $deliveryFee,
            'discount_cents' => 0,
            'delivery_fee_cents' => $deliveryFee,
            'total_cents' => $cents,
            'payment_method' => 'pix',
            'placed_at' => CarbonImmutable::parse($placedAtLocal, 'America/Sao_Paulo')->utc(),
        ]);

        Cache::flush();
    }
}
