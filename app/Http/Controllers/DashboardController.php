<?php

namespace App\Http\Controllers;

use App\Enums\FinanceType;
use App\Enums\Module;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Enums\TabStatus;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Store;
use App\Services\Billing\Subscription;
use App\Services\Finance\FinanceOverview;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\StoreReport;
use App\Services\Scheduling\StoreSchedule;
use App\Support\CurrentStore;
use App\Support\SlotLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Início": today's numbers, orders in progress, best sellers, best customers and tables.
 * Sales and customers are for owners and managers; the floor team sees orders and tables.
 */
class DashboardController extends Controller
{
    use InteractsWithCurrentStore;

    public function __invoke(CurrentStore $current): Response|RedirectResponse
    {
        // Waiters go straight to the tables, couriers to their deliveries.
        if ($current->role() === StoreRole::Waiter) {
            return to_route('tables.index');
        }

        if ($current->role() === StoreRole::Courier) {
            return to_route('deliveries.index');
        }

        $store = $this->currentStore();
        $seesSales = Gate::allows('manage-store');
        $now = CarbonImmutable::now();

        return Inertia::render('Dashboard', [
            'status' => [
                'isOpen' => StoreSchedule::for($store)->isOpenAt($now),
                'isPaused' => $store->is_paused,
            ],
            'today' => $seesSales ? $this->today($store) : null,
            'week' => $seesSales ? (new StoreReport($store, ReportPeriod::from('7d', null, null, $store->timezone)))->daily() : null,
            'topProducts' => $seesSales ? (new StoreReport($store, ReportPeriod::from('7d', null, null, $store->timezone)))->topProducts(5) : null,
            'topCustomers' => $seesSales ? (new StoreReport($store, ReportPeriod::from('30d', null, null, $store->timezone)))->topCustomers(5) : null,
            'orders' => Gate::allows('view-orders') ? $this->openOrders($store, $now) : null,
            'tables' => Gate::allows('serve-tables') && Subscription::for($store)->hasModule(Module::Tables) ? $this->tables($store) : null,
            'setup' => Gate::allows('manage-catalog') ? $this->setup($store) : null,
            'finance' => Gate::allows('manage-finance') && Subscription::for($store)->hasModule(Module::Finance) ? $this->finance($store) : null,
        ]);
    }

    /**
     * Bills that are late or fall due today, so they are not forgotten.
     *
     * @return array{overdue: array{count: int, cents: int}, today: array{count: int, cents: int}, overdueReceivable: array{count: int, cents: int}}|null
     */
    private function finance(Store $store): ?array
    {
        $overview = new FinanceOverview($store, CarbonImmutable::now($store->timezone)->startOfMonth());
        $data = [
            'overdue' => $overview->overdue(FinanceType::Payable),
            'today' => $overview->dueToday(FinanceType::Payable),
            'overdueReceivable' => $overview->overdue(FinanceType::Receivable),
        ];

        return $data['overdue']['count'] + $data['today']['count'] + $data['overdueReceivable']['count'] > 0 ? $data : null;
    }

    /**
     * Today against yesterday.
     *
     * @return array{current: array<string, int>, previous: array<string, int>}
     */
    private function today(Store $store): array
    {
        $period = ReportPeriod::from('today', null, null, $store->timezone);

        return [
            'current' => (new StoreReport($store, $period))->totals(),
            'previous' => (new StoreReport($store, $period->previous()))->totals(),
        ];
    }

    /**
     * @return array{count: int, pending: int, latest: list<array<string, mixed>>}
     */
    private function openOrders(Store $store, CarbonImmutable $now): array
    {
        // Orders still waiting for an online payment are not the store's yet.
        $final = [...array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->isFinal()), OrderStatus::PendingPayment];
        $query = $store->orders()->whereNotIn('status', $final)->whereNull('tab_id');

        return [
            'count' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', OrderStatus::Pending)->count(),
            'latest' => array_values($query
                ->latest('placed_at')
                ->latest('id')
                ->limit(6)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'customerName' => $order->customer_name,
                    'fulfillment' => $order->fulfillment->label(),
                    'status' => $order->status->value,
                    'statusLabel' => $order->status->label(),
                    'totalCents' => $order->total_cents,
                    'placedAt' => $order->placed_at->toIso8601String(),
                    'scheduledFor' => SlotLabel::describe($order->scheduled_for, $store->timezone, $now),
                ])
                ->all()),
        ];
    }

    /**
     * @return array{total: int, busy: int, closing: int, occupied: list<array<string, mixed>>}|null
     */
    private function tables(Store $store): ?array
    {
        $tables = $store->diningTables()->where('is_active', true)->with('activeTab')->orderBy('sort_order')->orderBy('id')->get();

        if ($tables->isEmpty()) {
            return null;
        }

        $tabIds = $tables->map(fn (DiningTable $table) => $table->activeTab?->id)->filter()->all();
        $consumed = $tabIds === [] ? collect() : Order::query()
            ->whereIn('tab_id', $tabIds)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->groupBy('tab_id')
            ->selectRaw('tab_id, sum(total_cents) as consumed')
            ->pluck('consumed', 'tab_id');

        $occupied = $tables->filter(fn (DiningTable $table) => $table->activeTab !== null);

        return [
            'total' => $tables->count(),
            // Tables joined to another table's tab are taken too.
            'busy' => $occupied->count() + $tables->whereNotNull('joined_tab_id')->count(),
            'closing' => $occupied->filter(fn (DiningTable $table) => $table->activeTab?->status === TabStatus::Closing)->count(),
            'occupied' => array_values($occupied->map(fn (DiningTable $table) => [
                'id' => $table->id,
                'name' => $table->name,
                'status' => $table->activeTab?->status->value,
                'people' => $table->activeTab?->people,
                'openedAt' => $table->activeTab?->opened_at->toIso8601String(),
                'consumedCents' => (int) ($consumed[$table->activeTab?->id] ?? 0),
            ])->all()),
        ];
    }

    /**
     * First steps, shown until the store is ready to sell.
     *
     * @return list<array{key: string, done: bool}>
     */
    private function setup(Store $store): array
    {
        return [
            ['key' => 'categories', 'done' => $store->categories()->exists()],
            ['key' => 'products', 'done' => $store->products()->exists()],
            ['key' => 'appearance', 'done' => $store->getAttribute('logo_path') !== null],
            ['key' => 'hours', 'done' => $store->openingHours()->exists()],
            ['key' => 'whatsapp', 'done' => $store->whatsapp_instance !== null],
        ];
    }
}
