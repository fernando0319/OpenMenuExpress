<?php

namespace Tests\Feature\Billing;

use App\Enums\Module;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
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

class FreePlanTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Plan $free;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.enabled' => true, 'billing.trial_days' => 14, 'billing.grace_days' => 7, 'billing.warn_days' => 5]);
        Cache::flush();

        // Mid-September, São Paulo time; the trial already ended.
        $this->travelTo(CarbonImmutable::parse('2026-09-15 15:00:00', 'UTC'));

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'timezone' => 'America/Sao_Paulo', 'trial_ends_at' => now()->subDays(20)]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);

        $this->free = Plan::query()->create([
            'name' => 'Grátis',
            'price_cents' => 0,
            'interval' => 'month',
            'free_up_to_cents' => 300000, // R$ 3.000
            'modules' => [Module::Reports->value],
        ]);
    }

    public function test_after_the_trial_a_store_falls_back_to_the_free_plan(): void
    {
        $subscription = Subscription::for($this->store);

        $this->assertSame(Subscription::FREE, $subscription->status);
        $this->assertSame('Grátis', $subscription->plan?->name);
        $this->assertSame('2026-09-30', $subscription->endsAt?->setTimezone('America/Sao_Paulo')->toDateString());
        $this->assertTrue($subscription->hasModule(Module::Reports));
        $this->assertFalse($subscription->hasModule(Module::Pos));
        $this->assertSame(['limitCents' => 300000, 'salesCents' => 0, 'overLimit' => false], $subscription->freeUsage());

        $this->actingAs($this->owner)->get(route('orders.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('pos.show'))->assertForbidden();
    }

    public function test_it_renews_by_itself_every_month_within_the_limit(): void
    {
        $this->sale(250000, '2026-09-10 20:00:00');

        $this->travelTo(CarbonImmutable::parse('2026-10-05 15:00:00', 'UTC'));
        Cache::flush();

        $this->assertSame(Subscription::FREE, Subscription::for($this->store)->status);
        $this->actingAs($this->owner)->get(route('orders.index'))->assertOk();
    }

    public function test_above_the_limit_the_store_pays_from_the_next_month(): void
    {
        $this->sale(200000, '2026-09-05 20:00:00');
        $this->sale(150000, '2026-09-12 20:00:00'); // R$ 3.500 in September

        // Still September: free, but warned.
        $september = Subscription::for($this->store);
        $this->assertSame(Subscription::FREE, $september->status);
        $this->assertTrue($september->shouldWarn());
        $this->assertTrue($september->freeUsage()['overLimit'] ?? false);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.subscription.free.salesCents', 350000)
                ->where('currentStore.subscription.warn', true));

        // October 3rd: grace, counting from October 1st.
        $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));
        $grace = Subscription::for($this->store);
        $this->assertSame(Subscription::GRACE, $grace->status);
        $this->assertSame(Subscription::REASON_OVER_LIMIT, $grace->reason);
        $this->assertSame('Acima do limite gratuito (em carência)', $grace->label());

        // October 9th: blocked until a paid plan comes in.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 15:00:00', 'UTC'));
        $this->assertTrue(Subscription::for($this->store)->isBlocked());
        $this->actingAs($this->owner)->get(route('orders.index'))->assertRedirect(route('billing.show'));

        // Paying for a plan brings it back.
        $paid = Plan::query()->create(['name' => 'Completo', 'price_cents' => 9990, 'interval' => 'month', 'modules' => Module::values()]);
        $this->store->update(['plan_id' => $paid->id, 'paid_until' => now()->addMonth()]);
        $this->assertSame(Subscription::ACTIVE, Subscription::for($this->store->fresh() ?? $this->store)->status);
    }

    public function test_when_a_paid_plan_ends_a_small_store_keeps_going_for_free(): void
    {
        $paid = Plan::query()->create(['name' => 'Completo', 'price_cents' => 9990, 'interval' => 'month', 'modules' => Module::values()]);
        $this->store->update(['plan_id' => $paid->id, 'paid_until' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC')]);
        $this->sale(80000, '2026-09-10 20:00:00'); // R$ 800

        $this->assertSame(Subscription::ACTIVE, Subscription::for($this->store->fresh() ?? $this->store)->status);

        $this->travelTo(CarbonImmutable::parse('2026-10-02 15:00:00', 'UTC'));
        $subscription = Subscription::for($this->store->fresh() ?? $this->store);

        $this->assertSame(Subscription::FREE, $subscription->status);
        $this->assertSame('Grátis', $subscription->plan?->name);
    }

    public function test_the_admin_sets_the_free_limit_on_a_plan(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), ['name' => 'Iniciante', 'price' => '0', 'interval' => 'month', 'free_up_to' => '5.000,00', 'modules' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame(500000, Plan::query()->where('name', 'Iniciante')->value('free_up_to_cents'));

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), ['name' => 'X', 'price' => '0', 'interval' => 'month', 'free_up_to' => 'muito', 'modules' => []])
            ->assertSessionHasErrors('free_up_to');
    }

    private function sale(int $cents, string $placedAtLocal): void
    {
        Order::query()->create([
            'store_id' => $this->store->id,
            'number' => (int) $this->store->orders()->max('number') + 1,
            'customer_name' => 'Cliente',
            'channel' => 'online',
            'fulfillment' => 'pickup',
            'status' => OrderStatus::Completed,
            'subtotal_cents' => $cents,
            'discount_cents' => 0,
            'delivery_fee_cents' => 0,
            'total_cents' => $cents,
            'payment_method' => 'pix',
            'placed_at' => CarbonImmutable::parse($placedAtLocal, 'America/Sao_Paulo')->utc(),
        ]);

        Cache::flush();
    }
}
