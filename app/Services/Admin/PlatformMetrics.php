<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\TabStatus;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Store;
use App\Models\Tab;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Numbers about the whole hosted platform, for the private admin panel.
 * Only aggregates: customer data stays inside each store.
 */
final class PlatformMetrics
{
    public const TIMEZONE = 'America/Sao_Paulo';

    /**
     * @return array<string, int>
     */
    public function totals(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $todayStart = $now->setTimezone(self::TIMEZONE)->startOfDay()->utc();
        $since30 = $now->subDays(30);

        return [
            'storesTotal' => Store::query()->count(),
            'storesActive7d' => Store::query()->whereHas('orders', fn ($query) => $query->where('placed_at', '>=', $now->subDays(7)))->count(),
            'storesNew30d' => Store::query()->where('created_at', '>=', $since30)->count(),
            'storesSuspended' => Store::query()->whereNotNull('suspended_at')->count(),
            'usersTotal' => User::query()->count(),
            'ordersToday' => Order::query()->where('placed_at', '>=', $todayStart)->count(),
            'orders30d' => Order::query()->where('placed_at', '>=', $since30)->count(),
            'gmv30dCents' => $this->gmvSince($since30),
            'messageFailures24h' => MessageLog::query()->where('status', MessageLog::STATUS_FAILED)->where('created_at', '>=', $now->subDay())->count(),
            'printFailures24h' => PrintJob::query()->where('status', PrintJob::STATUS_FAILED)->where('updated_at', '>=', $now->subDay())->count(),
        ];
    }

    /**
     * Orders and sales per day over the last days, in the platform timezone.
     *
     * @return list<array{date: string, ordersCount: int, gmvCents: int}>
     */
    public function daily(int $days = 30, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $start = $now->setTimezone(self::TIMEZONE)->startOfDay()->subDays($days - 1);
        $rows = [];

        for ($day = $start; $day->lessThanOrEqualTo($now); $day = $day->addDay()) {
            $rows[$day->format('Y-m-d')] = ['ordersCount' => 0, 'gmvCents' => 0];
        }

        $orders = Order::query()
            ->where('placed_at', '>=', $start->utc())
            ->toBase()
            ->get(['placed_at', 'status', 'total_cents', 'tab_id']);

        foreach ($orders as $order) {
            $date = CarbonImmutable::parse((string) $order->placed_at, 'UTC')->setTimezone(self::TIMEZONE)->format('Y-m-d');

            if (! isset($rows[$date])) {
                continue;
            }

            $rows[$date]['ordersCount']++;

            if ($order->status === OrderStatus::Completed->value && $order->tab_id === null) {
                $rows[$date]['gmvCents'] += (int) $order->total_cents;
            }
        }

        $tabs = Tab::query()
            ->where('status', TabStatus::Closed)
            ->where('closed_at', '>=', $start->utc())
            ->toBase()
            ->get(['closed_at', 'total_cents']);

        foreach ($tabs as $tab) {
            $date = CarbonImmutable::parse((string) $tab->closed_at, 'UTC')->setTimezone(self::TIMEZONE)->format('Y-m-d');

            if (isset($rows[$date])) {
                $rows[$date]['gmvCents'] += (int) $tab->total_cents;
            }
        }

        $list = [];

        foreach ($rows as $date => $row) {
            $list[] = ['date' => $date, ...$row];
        }

        return $list;
    }

    /**
     * Stores with their activity of the last 30 days, for the admin list.
     *
     * @return Builder<Store>
     */
    public function storesWithActivity(?CarbonImmutable $now = null): Builder
    {
        $since = ($now ?? CarbonImmutable::now())->subDays(30);

        return Store::query()
            ->withCount(['orders as orders_30d' => fn ($query) => $query->where('placed_at', '>=', $since)])
            ->withSum(['orders as order_sales_30d' => fn ($query) => $query
                ->where('placed_at', '>=', $since)
                ->where('status', OrderStatus::Completed)
                ->whereNull('tab_id')], 'total_cents')
            ->withSum(['tabs as tab_sales_30d' => fn ($query) => $query
                ->where('status', TabStatus::Closed)
                ->where('closed_at', '>=', $since)], 'total_cents')
            ->withMax('orders as last_order_at', 'placed_at')
            ->withCount('users as members_count')
            ->with(['users' => fn ($query) => $query->wherePivot('role', 'owner')->orderBy('users.id')]);
    }

    private function gmvSince(CarbonImmutable $since): int
    {
        $orders = (int) Order::query()
            ->where('placed_at', '>=', $since)
            ->where('status', OrderStatus::Completed)
            ->whereNull('tab_id')
            ->sum('total_cents');

        $tabs = (int) Tab::query()
            ->where('status', TabStatus::Closed)
            ->where('closed_at', '>=', $since)
            ->sum('total_cents');

        return $orders + $tabs;
    }
}
