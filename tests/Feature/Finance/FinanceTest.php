<?php

namespace Tests\Feature\Finance;

use App\Enums\CashMovementType;
use App\Enums\FinanceFrequency;
use App\Enums\FinanceType;
use App\Enums\Module;
use App\Enums\StoreRole;
use App\Models\CashMovement;
use App\Models\FinanceTitle;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Cash\CashRegister;
use App\Services\Finance\InstallmentPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'timezone' => 'America/Sao_Paulo']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_the_split_keeps_the_exact_total_and_the_month_end(): void
    {
        $plan = InstallmentPlan::split(10000, 3, CarbonImmutable::parse('2026-01-31'), FinanceFrequency::Monthly);

        $this->assertSame([3334, 3333, 3333], array_column($plan, 'amount_cents'));
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($plan, 'due_on'));

        $weekly = InstallmentPlan::split(9000, 3, CarbonImmutable::parse('2026-10-15'), FinanceFrequency::Weekly);
        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], array_column($weekly, 'due_on'));
    }

    public function test_a_purchase_is_recorded_in_installments(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->owner)
            ->post(route('finance.titles.store'), $this->payload([
                'supplier_id' => $supplier->id,
                'total' => '1.500,00',
                'installments' => [
                    ['due_on' => '2026-10-20', 'amount' => '500,00'],
                    ['due_on' => '2026-11-20', 'amount' => '500,00'],
                    ['due_on' => '2026-12-20', 'amount' => '500,00'],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $title = FinanceTitle::query()->sole();
        $this->assertSame(FinanceType::Payable, $title->type);
        $this->assertSame(150000, $title->total_cents);
        $this->assertSame(3, $title->installments_count);
        $this->assertSame('Atacadão', $title->partyName());
        $this->assertSame(['2026-10-20', '2026-11-20', '2026-12-20'], $title->installments->map(fn ($i) => $i->due_on->format('Y-m-d'))->all());
    }

    public function test_the_installments_must_add_up_to_the_total(): void
    {
        $this->actingAs($this->owner)
            ->post(route('finance.titles.store'), $this->payload([
                'total' => '100,00',
                'installments' => [['due_on' => '2026-10-20', 'amount' => '60,00'], ['due_on' => '2026-11-20', 'amount' => '30,00']],
            ]))
            ->assertSessionHasErrors('installments');

        $this->assertSame(0, FinanceTitle::query()->count());
    }

    public function test_paying_in_cash_takes_the_money_from_the_open_register_and_undo_puts_it_back(): void
    {
        $installment = $this->payable(20000)->installments()->firstOrFail();
        $register = app(CashRegister::class);
        $session = $register->open($this->store, $this->owner, 50000);

        $this->actingAs($this->owner)
            ->post(route('finance.installments.settle', $installment), [
                'paid_on' => '2026-10-15',
                'amount' => '210,00', // with interest
                'payment_method' => 'cash',
                'through_cash' => true,
            ])
            ->assertSessionHasNoErrors();

        $installment->refresh();
        $this->assertSame(21000, $installment->paid_cents);
        $this->assertSame('2026-10-15', $installment->paid_on?->format('Y-m-d'));
        $movement = CashMovement::query()->where('cash_session_id', $session->id)->sole();
        $this->assertSame(CashMovementType::Withdrawal, $movement->type);
        $this->assertSame(21000, $movement->amount_cents);

        $this->actingAs($this->owner)->delete(route('finance.installments.undo', $installment))->assertSessionHasNoErrors();

        $this->assertNull($installment->refresh()->paid_on);
        $this->assertSame(
            [CashMovementType::Withdrawal, CashMovementType::Supply],
            CashMovement::query()->orderBy('id')->get()->map(fn ($m) => $m->type)->all(),
        );
    }

    public function test_paying_through_the_register_needs_it_open(): void
    {
        $installment = $this->payable(20000)->installments()->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('finance.installments.settle', $installment), [
                'paid_on' => '2026-10-15', 'amount' => '200,00', 'payment_method' => 'cash', 'through_cash' => true,
            ])
            ->assertSessionHasErrors('through_cash');

        $this->assertNull($installment->refresh()->paid_on);
    }

    public function test_a_settlement_cannot_be_dated_in_the_future(): void
    {
        $installment = $this->payable(20000)->installments()->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('finance.installments.settle', $installment), [
                'paid_on' => '2026-10-16', 'amount' => '200,00', 'payment_method' => 'pix',
            ])
            ->assertSessionHasErrors('paid_on');
    }

    public function test_automatic_installments_are_settled_on_the_local_due_date(): void
    {
        $title = $this->payable(30000, [
            'auto_settle' => true,
            'auto_settle_method' => 'auto_debit',
            'installments' => [
                ['due_on' => '2026-10-16', 'amount' => '150,00'],
                ['due_on' => '2026-11-16', 'amount' => '150,00'],
            ],
        ]);

        // 16/10 01:00 UTC is still the 15th in São Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-10-16 01:00:00', 'UTC'));
        $this->artisan('app:auto-settle-installments')->assertSuccessful();
        $this->assertSame(0, $title->installments()->whereNotNull('paid_on')->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-16 04:00:00', 'UTC'));
        $this->artisan('app:auto-settle-installments')->assertSuccessful();

        $first = $title->installments()->firstOrFail();
        $this->assertSame('2026-10-16', $first->paid_on?->format('Y-m-d'));
        $this->assertTrue($first->settled_automatically);
        $this->assertSame(1, $title->installments()->whereNotNull('paid_on')->count());
    }

    public function test_after_a_payment_only_the_description_can_change(): void
    {
        $title = $this->payable(20000, ['installments' => [
            ['due_on' => '2026-10-10', 'amount' => '100,00'],
            ['due_on' => '2026-11-10', 'amount' => '100,00'],
        ]]);
        $first = $title->installments()->firstOrFail();
        $first->update(['paid_on' => '2026-10-10', 'paid_cents' => 10000, 'payment_method' => 'pix']);

        $this->actingAs($this->owner)
            ->put(route('finance.titles.update', $title), $this->payload([
                'description' => 'Compra de queijos',
                'total' => '999,00',
                'installments' => [['due_on' => '2026-12-01', 'amount' => '999,00']],
            ]))
            ->assertSessionHasNoErrors();

        $title->refresh();
        $this->assertSame('Compra de queijos', $title->description);
        $this->assertSame(20000, $title->total_cents);
        $this->assertSame(2, $title->installments()->count());

        // A title with a payment is canceled, not deleted.
        $this->actingAs($this->owner)->delete(route('finance.titles.destroy', $title));
        $this->assertModelExists($title);
        $this->actingAs($this->owner)->post(route('finance.titles.cancel', $title));
        $this->assertNotNull($title->refresh()->canceled_at);
    }

    public function test_the_lists_show_overdue_and_the_summary(): void
    {
        $this->payable(10000, ['installments' => [['due_on' => '2026-10-01', 'amount' => '100,00']]]);
        $this->payable(5000, ['description' => 'Gás', 'installments' => [['due_on' => '2026-10-15', 'amount' => '50,00']]]);
        $this->payable(7000, ['type' => 'receivable', 'description' => 'Festa da empresa', 'counterparty' => 'Empresa X', 'installments' => [['due_on' => '2026-10-20', 'amount' => '70,00']]]);

        $this->actingAs($this->owner)
            ->get(route('finance.payables', ['status' => 'overdue']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('finance/Titles')
                ->has('installments.data', 1)
                ->where('installments.data.0.status', 'overdue')
                ->where('installments.data.0.daysLate', 14)
                ->where('summary.overdue.cents', 10000)
                ->where('summary.today.cents', 5000));

        // Due today is still open, late in the evening in São Paulo (already the next day in UTC).
        $this->travelTo(CarbonImmutable::parse('2026-10-16 02:30:00', 'UTC'));
        $this->actingAs($this->owner)
            ->get(route('finance.payables'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('installments.data.1.description', 'Gás')
                ->where('installments.data.1.status', 'open')
                ->where('installments.data.0.daysLate', 14));
        $this->travelTo(CarbonImmutable::parse('2026-10-15 15:00:00', 'UTC'));

        $this->actingAs($this->owner)
            ->get(route('finance.receivables'))
            ->assertInertia(fn (Assert $page) => $page->has('installments.data', 1)->where('installments.data.0.party', 'Empresa X'));

        $this->actingAs($this->owner)
            ->get(route('finance.overview'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('finance/Overview')
                ->where('overview.toPayCents', 15000)
                ->where('overview.toReceiveCents', 7000)
                ->where('overview.overdue.payable.count', 1)
                ->has('upcoming', 3));
    }

    public function test_suppliers_validate_the_document_and_keep_history(): void
    {
        $this->actingAs($this->owner)
            ->post(route('finance.suppliers.store'), ['name' => 'Distribuidora', 'document' => '11.111.111/1111-11'])
            ->assertSessionHasErrors('document');

        $this->actingAs($this->owner)
            ->post(route('finance.suppliers.store'), ['name' => 'Distribuidora', 'document' => '11.222.333/0001-81', 'phone' => '(41) 99999-8888'])
            ->assertSessionHasNoErrors();

        $supplier = Supplier::query()->sole();
        $this->assertSame('11222333000181', $supplier->document);
        $this->assertSame('5541999998888', $supplier->phone);
        $this->assertSame('11.222.333/0001-81', $supplier->formattedDocument());

        $this->payable(1000, ['supplier_id' => $supplier->id]);
        $this->actingAs($this->owner)->delete(route('finance.suppliers.destroy', $supplier));
        $this->assertFalse($supplier->refresh()->is_active);
    }

    public function test_only_owners_and_managers_see_finance_and_only_their_store(): void
    {
        $attendant = User::factory()->create();
        $this->store->users()->attach($attendant, ['role' => StoreRole::Attendant->value]);
        $this->actingAs($attendant)->get(route('finance.overview'))->assertForbidden();

        $other = Store::factory()->create();
        $foreign = FinanceTitle::query()->create([
            'store_id' => $other->id, 'type' => 'payable', 'description' => 'X', 'issued_on' => '2026-10-01', 'total_cents' => 100,
            'frequency' => 'monthly',
        ]);
        $this->actingAs($this->owner)->get(route('finance.titles.show', $foreign))->assertNotFound();
    }

    public function test_finance_is_a_plan_module(): void
    {
        config(['billing.enabled' => true]);
        $plan = Plan::query()->create(['name' => 'Básico', 'price_cents' => 4990, 'interval' => 'month', 'modules' => [Module::Pos->value]]);
        $this->store->update(['plan_id' => $plan->id, 'paid_until' => now()->addMonth(), 'trial_ends_at' => now()->subDay()]);

        $this->actingAs($this->owner)->get(route('finance.overview'))->assertForbidden();
    }

    private function supplier(): Supplier
    {
        return $this->store->suppliers()->create(['name' => 'Atacadão']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'type' => 'payable',
            'description' => 'Compra de carnes',
            'issued_on' => '2026-10-15',
            'total' => '200,00',
            'frequency' => 'monthly',
            'installments' => [['due_on' => '2026-10-20', 'amount' => '200,00']],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payable(int $cents, array $overrides = []): FinanceTitle
    {
        $overrides += ['total' => intdiv($cents, 100).','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT)];
        $overrides['installments'] ??= [['due_on' => '2026-10-20', 'amount' => $overrides['total']]];

        $this->actingAs($this->owner)->post(route('finance.titles.store'), $this->payload($overrides))->assertSessionHasNoErrors();

        return FinanceTitle::query()->latest('id')->firstOrFail();
    }
}
