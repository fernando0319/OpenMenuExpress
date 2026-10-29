<?php

namespace Tests\Feature\Billing;

use App\Enums\Module;
use App\Enums\StoreRole;
use App\Models\AdminAuditLog;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Billing\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private User $admin;

    private Plan $basic;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.enabled' => true, 'billing.trial_days' => 14, 'billing.grace_days' => 7, 'billing.warn_days' => 5]);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00'));

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->basic = Plan::query()->create(['name' => 'Básico', 'price_cents' => 4990, 'interval' => 'month', 'modules' => [Module::Reports->value]]);
    }

    public function test_a_new_store_lives_through_trial_grace_and_block(): void
    {
        $this->assertSame(Subscription::TRIAL, Subscription::for($this->store)->status);
        $this->assertTrue(Subscription::for($this->store)->hasModule(Module::Pos)); // trial sees everything

        $this->travel(10)->days();
        $this->assertTrue(Subscription::for($this->store)->shouldWarn());

        $this->travel(5)->days(); // day 15: trial over, grace
        $this->assertSame(Subscription::GRACE, Subscription::for($this->store)->status);
        $this->actingAs($this->owner)->get(route('orders.index'))->assertOk();
        $this->get('/pizzaria-ze')->assertOk();

        $this->travel(7)->days(); // grace over: blocked
        $this->assertTrue(Subscription::for($this->store)->isBlocked());
        $this->actingAs($this->owner)->get(route('orders.index'))->assertRedirect(route('billing.show'));
        $this->actingAs($this->owner)->get(route('billing.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('billing/Show')
            ->where('subscription.blocked', true));
        $this->get('/pizzaria-ze')->assertStatus(503);
        $this->post('/pizzaria-ze/orders', [])->assertStatus(503);
    }

    public function test_a_voucher_brings_the_store_back_and_stacks_on_paid_time(): void
    {
        $this->travel(30)->days(); // blocked
        [$voucher] = $this->vouchers(days: 30);

        $this->actingAs($this->owner)
            ->post(route('billing.redeem'), ['code' => strtolower(str_replace('-', ' ', $voucher->code))])
            ->assertSessionHasNoErrors();

        $store = $this->store->fresh();
        $this->assertNotNull($store);
        $this->assertSame($this->basic->id, $store->plan_id);
        $this->assertSame('2026-12-31', $store->paid_until?->toDateString()); // Dec 1 + 30 days
        $this->actingAs($this->owner)->get(route('orders.index'))->assertOk();

        // The same code cannot be used twice; a second voucher adds on top.
        $this->actingAs($this->owner)->post(route('billing.redeem'), ['code' => $voucher->code])->assertSessionHasErrors('code');
        [$second] = $this->vouchers(days: 30);
        $this->actingAs($this->owner)->post(route('billing.redeem'), ['code' => $second->code])->assertSessionHasNoErrors();
        $this->assertSame('2027-01-30', $this->store->fresh()?->paid_until?->toDateString());
    }

    public function test_voucher_rules(): void
    {
        $this->actingAs($this->owner)->post(route('billing.redeem'), ['code' => 'MX-AAAA-AAAA'])->assertSessionHasErrors(['code' => 'Código inválido. Confira e tente de novo.']);

        [$expired] = $this->vouchers(expiresAt: '2026-11-05');
        $this->travel(10)->days();
        $this->actingAs($this->owner)->post(route('billing.redeem'), ['code' => $expired->code])->assertSessionHasErrors(['code' => 'Este código expirou.']);

        [$disabled] = $this->vouchers();
        $this->actingAs($this->admin)->patch(route('admin.vouchers.toggle', $disabled));
        $this->actingAs($this->owner)->post(route('billing.redeem'), ['code' => $disabled->code])->assertSessionHasErrors('code');

        $attendant = User::factory()->create();
        $this->store->users()->attach($attendant, ['role' => StoreRole::Attendant->value]);
        [$voucher] = $this->vouchers();
        $this->actingAs($attendant)->post(route('billing.redeem'), ['code' => $voucher->code])->assertForbidden();
    }

    public function test_paid_plans_unlock_only_their_modules(): void
    {
        [$voucher] = $this->vouchers();
        $this->actingAs($this->owner)->post(route('billing.redeem'), ['code' => $voucher->code]);
        $this->travel(20)->days(); // trial over, paid plan active

        $this->assertSame(Subscription::ACTIVE, Subscription::for($this->store->fresh() ?? $this->store)->status);
        $this->actingAs($this->owner)->get(route('reports.index'))->assertOk();
        $this->actingAs($this->owner)
            ->get(route('pos.show'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('billing/Upgrade')->where('module.value', 'pos'));
        $this->actingAs($this->owner)->get(route('tables.index'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('currentStore.subscription.modules', ['reports'])
            ->where('tables', null));
    }

    public function test_admin_releases_access_manually_and_grants_complimentary(): void
    {
        $this->travel(30)->days(); // blocked

        $this->actingAs($this->admin)
            ->post(route('admin.stores.billing.extend', $this->store), ['days' => 60, 'plan_id' => $this->basic->id, 'note' => 'PIX recebido'])
            ->assertSessionHasNoErrors();

        $store = $this->store->fresh();
        $this->assertSame('2027-01-30', $store?->paid_until?->toDateString());
        $this->assertSame($this->basic->id, $store?->plan_id);

        $this->actingAs($this->admin)
            ->put(route('admin.stores.billing.complimentary', $this->store), ['complimentary' => true, 'note' => 'Parceiro'])
            ->assertSessionHasNoErrors();
        $this->travel(400)->days();
        $this->assertSame(Subscription::COMPLIMENTARY, Subscription::for($this->store->fresh() ?? $this->store)->status);

        $this->assertSame(
            ['billing.extend', 'billing.complimentary'],
            AdminAuditLog::query()->orderBy('id')->pluck('action')->all(),
        );

        $this->actingAs($this->owner)->post(route('admin.stores.billing.extend', $this->store), ['days' => 30])->assertForbidden();
    }

    public function test_admin_manages_plans_and_generates_vouchers(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.plans.store'), ['name' => 'Completo', 'price' => '99,90', 'interval' => 'month', 'modules' => Module::values(), 'is_active' => true])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post(route('admin.plans.store'), ['name' => 'X', 'price' => 'abc', 'interval' => 'month', 'modules' => []])
            ->assertSessionHasErrors('price');

        $complete = Plan::query()->where('name', 'Completo')->sole();
        $this->assertSame(9990, $complete->price_cents);

        $this->actingAs($this->admin)
            ->post(route('admin.vouchers.store'), ['plan_id' => $complete->id, 'days' => 30, 'quantity' => 3, 'max_redemptions' => 1])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('generated_codes', fn (array $codes) => count($codes) === 3 && preg_match('/^MX-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $codes[0]) === 1);

        $this->assertSame(3, Voucher::query()->count());
        $this->actingAs($this->owner)->get(route('admin.vouchers.index'))->assertForbidden();
    }

    public function test_with_billing_off_nothing_is_ever_blocked(): void
    {
        config(['billing.enabled' => false]);
        $this->travel(5)->years();

        $this->assertSame(Subscription::DISABLED, Subscription::for($this->store)->status);
        $this->actingAs($this->owner)->get(route('pos.show'))->assertOk();
        $this->actingAs($this->owner)->get(route('billing.show'))->assertRedirect(route('dashboard'));
    }

    public function test_messages_are_not_sent_without_the_whatsapp_module(): void
    {
        $this->store->update(['whatsapp_enabled' => true, 'whatsapp_instance' => 'mx-teste', 'plan_id' => $this->basic->id, 'paid_until' => now()->addMonth(), 'trial_ends_at' => now()->subDay()]);
        $product = Product::factory()->for(Category::factory()->for($this->store))->create();

        $this->post('/pizzaria-ze/orders', [
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '11999998888'],
            'payment_method' => 'pix',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->store->messageLogs()->count());
    }

    /**
     * @return list<Voucher>
     */
    private function vouchers(int $days = 30, ?string $expiresAt = null): array
    {
        $this->actingAs($this->admin)->post(route('admin.vouchers.store'), [
            'plan_id' => $this->basic->id,
            'days' => $days,
            'quantity' => 1,
            'max_redemptions' => 1,
            'expires_at' => $expiresAt,
        ])->assertSessionHasNoErrors();

        return [Voucher::query()->latest('id')->firstOrFail()];
    }
}
