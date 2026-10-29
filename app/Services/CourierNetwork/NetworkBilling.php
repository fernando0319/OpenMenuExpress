<?php

namespace App\Services\CourierNetwork;

use App\Enums\BillingCycle;
use App\Enums\NetworkDeliveryStatus;
use App\Models\AdminAuditLog;
use App\Models\CourierPayout;
use App\Models\DeliveryBill;
use App\Models\NetworkCourier;
use App\Models\NetworkDelivery;
use App\Models\Store;
use App\Models\User;
use App\Support\NetworkSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The money of the courier network. Stores pay the platform for their delivered runs in
 * daily or weekly bills; the platform pays each courier weekly, minus its cut. Payments
 * happen outside the system (PIX): here they are calculated and recorded.
 */
final class NetworkBilling
{
    /**
     * Closes every finished day or week of delivered runs into bills. Idempotent.
     *
     * @return int Bills created or updated.
     */
    public function closeBills(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $touched = 0;

        $storeIds = NetworkDelivery::query()
            ->where('status', NetworkDeliveryStatus::Delivered)
            ->whereNull('delivery_bill_id')
            ->distinct()
            ->pluck('store_id');

        foreach (Store::query()->whereIn('id', $storeIds)->get() as $store) {
            $today = $now->setTimezone($store->timezone)->startOfDay();
            $cycle = $store->network_billing_cycle;

            $periods = NetworkDelivery::query()
                ->where('store_id', $store->id)
                ->where('status', NetworkDeliveryStatus::Delivered)
                ->whereNull('delivery_bill_id')
                ->get()
                ->groupBy(fn (NetworkDelivery $delivery) => $this->billPeriod($delivery, $store, $cycle)[0]->format('Y-m-d'));

            foreach ($periods as $deliveries) {
                [$start, $end] = $this->billPeriod($deliveries->firstOrFail(), $store, $cycle);

                if ($end->greaterThanOrEqualTo($today)) {
                    continue; // still running
                }

                $this->attachToBill($store, $cycle, $start, $end, $deliveries);
                $touched++;
            }
        }

        return $touched;
    }

    /**
     * Closes every finished week (Monday to Sunday) of delivered runs into courier payouts. Idempotent.
     *
     * @return int Payouts created or updated.
     */
    public function closePayouts(?CarbonImmutable $now = null): int
    {
        $weekStart = ($now ?? CarbonImmutable::now())->setTimezone(NetworkSettings::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        $touched = 0;

        $deliveries = NetworkDelivery::query()
            ->where('status', NetworkDeliveryStatus::Delivered)
            ->whereNull('courier_payout_id')
            ->whereNotNull('network_courier_id')
            ->where('delivered_at', '<', $weekStart->utc())
            ->with('courier')
            ->get();

        $groups = $deliveries->groupBy(fn (NetworkDelivery $delivery) => $delivery->network_courier_id.'|'.$this->payoutWeek($delivery)->format('Y-m-d'));

        foreach ($groups as $group) {
            $courier = $group->firstOrFail()->courier;

            if (! $courier instanceof NetworkCourier) {
                continue;
            }

            $start = $this->payoutWeek($group->firstOrFail());

            DB::transaction(function () use ($courier, $start, $group): void {
                $payout = CourierPayout::query()->firstOrCreate(
                    ['network_courier_id' => $courier->id, 'period_start' => $start->format('Y-m-d'), 'status' => CourierPayout::PENDING],
                    [
                        'period_end' => $start->addDays(6)->format('Y-m-d'),
                        'deliveries_count' => 0,
                        'amount_cents' => 0,
                        'pix_key_type' => $courier->pix_key_type,
                        'pix_key' => $courier->pix_key,
                    ],
                );

                NetworkDelivery::query()->whereKey($group->modelKeys())->update(['courier_payout_id' => $payout->id]);

                $payout->update([
                    'deliveries_count' => $payout->deliveries()->count(),
                    'amount_cents' => (int) $payout->deliveries()->sum('courier_cents'),
                ]);
            });

            $touched++;
        }

        return $touched;
    }

    /**
     * An open bill past its due date and the grace days: the store cannot call the network.
     */
    public function overdueBill(Store $store, ?CarbonImmutable $now = null): ?DeliveryBill
    {
        $graceDays = NetworkSettings::all()['bill_grace_days'];

        return $store->deliveryBills()
            ->where('status', DeliveryBill::OPEN)
            ->where('due_at', '<', ($now ?? CarbonImmutable::now())->subDays($graceDays))
            ->orderBy('due_at')
            ->first();
    }

    /**
     * Delivered runs of the store not yet in a bill: what the next bill will hold.
     *
     * @return array{count: int, amountCents: int}
     */
    public function unbilled(Store $store): array
    {
        $query = $store->networkDeliveries()->where('status', NetworkDeliveryStatus::Delivered)->whereNull('delivery_bill_id');

        return ['count' => (clone $query)->count(), 'amountCents' => (int) $query->sum('fee_cents')];
    }

    /**
     * Payment received outside the system, or the bill forgiven.
     */
    public function settleBill(DeliveryBill $bill, string $status, User $by, ?string $note, ?string $ip = null): void
    {
        $bill->update(['status' => $status, 'settled_at' => now(), 'settled_by' => $by->id, 'note' => $note]);

        $this->log($by, $bill->store_id, $status === DeliveryBill::PAID ? 'network.bill_paid' : 'network.bill_waived', [
            'bill' => $bill->id,
            'period' => $bill->periodLabel(),
            'amount_cents' => $bill->amount_cents,
            'note' => $note,
        ], $ip);
    }

    public function reopenBill(DeliveryBill $bill, User $by, ?string $ip = null): void
    {
        $bill->update(['status' => DeliveryBill::OPEN, 'settled_at' => null, 'settled_by' => null]);

        $this->log($by, $bill->store_id, 'network.bill_reopened', ['bill' => $bill->id], $ip);
    }

    public function markPayoutPaid(CourierPayout $payout, User $by, ?string $note, ?string $ip = null): void
    {
        $payout->update(['status' => CourierPayout::PAID, 'paid_at' => now(), 'paid_by' => $by->id, 'note' => $note]);

        $this->log($by, null, 'network.payout_paid', [
            'payout' => $payout->id,
            'courier' => $payout->network_courier_id,
            'amount_cents' => $payout->amount_cents,
            'note' => $note,
        ], $ip);
    }

    public function reopenPayout(CourierPayout $payout, User $by, ?string $ip = null): void
    {
        $payout->update(['status' => CourierPayout::PENDING, 'paid_at' => null, 'paid_by' => null]);

        $this->log($by, null, 'network.payout_reopened', ['payout' => $payout->id], $ip);
    }

    /**
     * Day or week (store timezone) a delivered run belongs to, as [first day, last day].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function billPeriod(NetworkDelivery $delivery, Store $store, BillingCycle $cycle): array
    {
        $day = ($delivery->delivered_at ?? $delivery->updated_at ?? CarbonImmutable::now())->setTimezone($store->timezone)->startOfDay();

        return $cycle === BillingCycle::Daily
            ? [$day, $day]
            : [$day->startOfWeek(CarbonImmutable::MONDAY), $day->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)];
    }

    private function payoutWeek(NetworkDelivery $delivery): CarbonImmutable
    {
        return ($delivery->delivered_at ?? CarbonImmutable::now())->setTimezone(NetworkSettings::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /**
     * Daily bills fall due at the end of the next day; weekly ones at the end of Tuesday.
     */
    private function dueAt(Store $store, BillingCycle $cycle, CarbonImmutable $end): CarbonImmutable
    {
        $days = $cycle === BillingCycle::Daily ? 1 : 2;

        return CarbonImmutable::parse($end->format('Y-m-d'), $store->timezone)->addDays($days)->endOfDay()->utc();
    }

    /**
     * @param  Collection<int, NetworkDelivery>  $deliveries
     */
    private function attachToBill(Store $store, BillingCycle $cycle, CarbonImmutable $start, CarbonImmutable $end, Collection $deliveries): void
    {
        DB::transaction(function () use ($store, $cycle, $start, $end, $deliveries): void {
            // Runs that arrive late for a period still open join its bill; a settled bill is never changed.
            $bill = DeliveryBill::query()->firstOrCreate(
                [
                    'store_id' => $store->id,
                    'period_start' => $start->format('Y-m-d'),
                    'period_end' => $end->format('Y-m-d'),
                    'status' => DeliveryBill::OPEN,
                ],
                [
                    'cycle' => $cycle,
                    'deliveries_count' => 0,
                    'amount_cents' => 0,
                    'due_at' => $this->dueAt($store, $cycle, $end),
                ],
            );

            NetworkDelivery::query()->whereKey($deliveries->pluck('id')->all())->update(['delivery_bill_id' => $bill->id]);

            $bill->update([
                'deliveries_count' => $bill->deliveries()->count(),
                'amount_cents' => (int) $bill->deliveries()->sum('fee_cents'),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(User $admin, ?int $storeId, string $action, array $details, ?string $ip): void
    {
        AdminAuditLog::query()->create([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'action' => $action,
            'details' => $details,
            'ip' => $ip,
        ]);
    }
}
