<?php

namespace App\Services\Billing;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\StoreReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A store's sales in a calendar month (store timezone), with the same rule as the reports:
 * completed orders plus closed table bills. Cached, since the subscription is checked on
 * every request: a finished month never changes, the current one is refreshed often.
 */
final class MonthlySales
{
    public static function of(Store $store, CarbonImmutable $monthStart, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $local = $monthStart->setTimezone($store->timezone)->startOfMonth();
        $isCurrent = $local->format('Y-m') === $now->setTimezone($store->timezone)->format('Y-m');
        $key = "billing:sales:{$store->id}:{$local->format('Y-m')}";

        $compute = function () use ($store, $local): int {
            $period = ReportPeriod::from(
                'custom',
                $local->format('Y-m-d'),
                $local->endOfMonth()->format('Y-m-d'),
                $store->timezone,
            );

            return (new StoreReport($store, $period))->totals()['revenueCents'];
        };

        return (int) Cache::remember($key, $isCurrent ? now()->addMinutes(10) : now()->addDays(40), $compute);
    }

    /**
     * The base of plans charged by use: completed orders of the online menu in the month, by the
     * value of the products (the delivery fee is the store's), from $from on when given (the
     * month the trial ended counts only after it).
     */
    public static function online(Store $store, CarbonImmutable $monthStart, ?CarbonImmutable $from = null): int
    {
        $local = $monthStart->setTimezone($store->timezone)->startOfMonth();
        $start = $from !== null && $from->greaterThan($local) ? $from : $local;
        $end = $local->endOfMonth();

        if ($start->greaterThan($end)) {
            return 0;
        }

        return (int) Order::query()
            ->where('store_id', $store->id)
            ->where('channel', OrderChannel::Online)
            ->where('status', OrderStatus::Completed)
            ->whereBetween('placed_at', [$start->utc(), $end->utc()])
            ->sum(DB::raw('total_cents - delivery_fee_cents'));
    }

    /**
     * Forgets the cached figures (after an admin adjustment, or in tests).
     */
    public static function forget(Store $store, CarbonImmutable $monthStart): void
    {
        Cache::forget("billing:sales:{$store->id}:{$monthStart->setTimezone($store->timezone)->format('Y-m')}");
    }
}
