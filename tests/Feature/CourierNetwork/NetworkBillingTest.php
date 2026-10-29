<?php

namespace Tests\Feature\CourierNetwork;

use App\Enums\BillingCycle;
use App\Enums\NetworkDeliveryStatus;
use App\Models\CourierPayout;
use App\Models\DeliveryBill;
use App\Models\NetworkCourier;
use App\Models\NetworkDelivery;
use App\Models\User;
use App\Services\CourierNetwork\Dispatcher;
use App\Services\CourierNetwork\NetworkBilling;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkBillingTest extends TestCase
{
    use BuildsNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpNetwork();
    }

    public function test_a_daily_store_gets_a_bill_per_day_due_the_next_day(): void
    {
        $this->store->update(['network_billing_cycle' => BillingCycle::Daily]);
        $courier = $this->courierKmNorth(1);

        // Wednesday 21/10, 20:00 in São Paulo.
        $this->deliverAt($courier, '2026-10-21 20:00', 1000);
        $this->deliverAt($courier, '2026-10-21 22:30', 600);

        $billing = app(NetworkBilling::class);
        $this->assertSame(0, $billing->closeBills($this->local('2026-10-21 23:00')), 'The day is not over yet.');

        $billing->closeBills($this->local('2026-10-22 00:10'));
        $billing->closeBills($this->local('2026-10-22 01:10')); // idempotent

        $bill = DeliveryBill::query()->sole();
        $this->assertSame('2026-10-21', $bill->period_start->format('Y-m-d'));
        $this->assertSame(2, $bill->deliveries_count);
        $this->assertSame(1600, $bill->amount_cents);
        $this->assertTrue($bill->due_at->equalTo($this->local('2026-10-22 23:59:59')));

        // Due on Thursday night, one grace day: blocked from Saturday on.
        $this->assertNull($billing->overdueBill($this->store, $this->local('2026-10-23 12:00')));
        $this->assertNotNull($billing->overdueBill($this->store, $this->local('2026-10-24 00:30')));
    }

    public function test_a_weekly_store_gets_monday_to_sunday_due_on_tuesday(): void
    {
        $courier = $this->courierKmNorth(1);

        $this->deliverAt($courier, '2026-10-19 12:00', 800); // Monday
        $this->deliverAt($courier, '2026-10-25 23:00', 800); // Sunday
        $this->deliverAt($courier, '2026-10-26 10:00', 800); // next Monday

        app(NetworkBilling::class)->closeBills($this->local('2026-10-26 12:00'));

        $bill = DeliveryBill::query()->sole();
        $this->assertSame('19/10 a 25/10/2026', $bill->periodLabel());
        $this->assertSame(1600, $bill->amount_cents);
        $this->assertTrue($bill->due_at->equalTo($this->local('2026-10-27 23:59:59')));
        $this->assertSame(1, NetworkDelivery::query()->whereNull('delivery_bill_id')->count());
    }

    public function test_couriers_are_paid_weekly_what_is_left_after_the_platform_cut(): void
    {
        $carlos = $this->courierKmNorth(1, 'Carlos');
        $ana = $this->courierKmNorth(1, 'Ana');

        $this->deliverAt($carlos, '2026-10-20 12:00', 1000);
        $this->deliverAt($carlos, '2026-10-24 12:00', 1000);
        $this->deliverAt($ana, '2026-10-22 12:00', 600);
        $this->deliverAt($carlos, '2026-10-26 12:00', 1000); // this week: not yet

        $billing = app(NetworkBilling::class);
        $this->assertSame(0, $billing->closePayouts($this->local('2026-10-25 22:00')));

        $billing->closePayouts($this->local('2026-10-26 13:00'));
        $billing->closePayouts($this->local('2026-10-26 14:00'));

        $this->assertSame(2, CourierPayout::query()->count());
        $payout = CourierPayout::query()->where('network_courier_id', $carlos->id)->sole();
        $this->assertSame(1700, $payout->amount_cents, '2 x R$ 10,00 minus 15%.');
        $this->assertSame(2, $payout->deliveries_count);
        $this->assertSame($carlos->pix_key, $payout->pix_key);
        $this->assertSame('19/10 a 25/10/2026', $payout->periodLabel());
    }

    public function test_the_platform_owner_records_payments(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $courier = $this->courierKmNorth(1);
        $this->deliverAt($courier, '2026-10-20 12:00', 1000);
        app(NetworkBilling::class)->closeBills($this->local('2026-10-27 12:00'));
        app(NetworkBilling::class)->closePayouts($this->local('2026-10-27 12:00'));

        $bill = DeliveryBill::query()->sole();
        $payout = CourierPayout::query()->sole();

        $this->actingAs($this->owner)->post(route('admin.network.bills.settle', $bill), ['status' => 'paid'])->assertForbidden();

        $this->actingAs($admin)->post(route('admin.network.bills.settle', $bill), ['status' => 'paid', 'note' => 'PIX 27/10'])->assertSessionHasNoErrors();
        $this->assertSame(DeliveryBill::PAID, $bill->fresh()?->status);

        $csv = $this->actingAs($admin)->get(route('admin.network.payouts.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Carlos Motoboy', $csv);
        $this->assertStringContainsString('8,50', $csv);

        $this->actingAs($admin)->post(route('admin.network.payouts.pay', $payout))->assertSessionHasNoErrors();
        $this->assertSame(CourierPayout::PAID, $payout->fresh()?->status);

        $this->actingAs($admin)->get(route('admin.network.bills.index', ['status' => 'all']))->assertOk();
        $this->actingAs($admin)->get(route('admin.network.payouts.index'))->assertOk();
    }

    private function deliverAt(NetworkCourier $courier, string $local, int $feeCents): NetworkDelivery
    {
        $delivery = app(Dispatcher::class)->call($this->deliveryOrder(), $feeCents, $this->owner);

        $delivery->update([
            'network_courier_id' => $courier->id,
            'status' => NetworkDeliveryStatus::Delivered,
            'delivered_at' => $this->local($local),
        ]);

        return $delivery;
    }

    private function local(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'America/Sao_Paulo')->utc();
    }
}
