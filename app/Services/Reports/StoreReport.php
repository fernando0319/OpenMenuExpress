<?php

namespace App\Services\Reports;

use App\Enums\CashMovementType;
use App\Enums\Fulfillment;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TabStatus;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\Tab;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sales figures of a store for a period.
 *
 * A sale is a completed order (menu, PDV or delivery) or a closed table bill. Table rounds
 * are not counted on their own: their value is inside the bill, with service fee and discount.
 */
final class StoreReport
{
    /** @var Collection<int, array{at: CarbonImmutable, total: int, channel: string}>|null */
    private ?Collection $sales = null;

    public function __construct(
        private readonly Store $store,
        private readonly ReportPeriod $period,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $current = $this->totals();
        $previous = (new self($this->store, $this->period->previous()))->totals();

        return [
            'totals' => $current,
            'previous' => $previous,
            'daily' => $this->daily(),
            'hourly' => $this->hourly(),
            'channels' => $this->channels(),
            'payments' => $this->payments(),
            'topProducts' => $this->topProducts(),
            'topCustomers' => $this->topCustomers(),
            'coupons' => $this->coupons(),
        ];
    }

    /**
     * @return array{revenueCents: int, salesCount: int, averageTicketCents: int, cancelledCount: int, cancelledCents: int, discountCents: int, serviceFeeCents: int, deliveryFeeCents: int}
     */
    public function totals(): array
    {
        $orders = $this->completedOrders()
            ->selectRaw('count(*) as sales, coalesce(sum(total_cents), 0) as revenue, coalesce(sum(discount_cents), 0) as discount, coalesce(sum(delivery_fee_cents), 0) as delivery')
            ->toBase()
            ->first();

        $tabs = $this->closedTabs()
            ->selectRaw('count(*) as sales, coalesce(sum(total_cents), 0) as revenue, coalesce(sum(discount_cents), 0) as discount, coalesce(sum(service_fee_cents), 0) as service')
            ->toBase()
            ->first();

        $cancelled = $this->ordersPlaced()
            ->where('status', OrderStatus::Cancelled)
            ->selectRaw('count(*) as quantity, coalesce(sum(total_cents), 0) as value')
            ->toBase()
            ->first();

        $count = (int) ($orders->sales ?? 0) + (int) ($tabs->sales ?? 0);
        $revenue = (int) ($orders->revenue ?? 0) + (int) ($tabs->revenue ?? 0);

        return [
            'revenueCents' => $revenue,
            'salesCount' => $count,
            'averageTicketCents' => $count > 0 ? intdiv($revenue, $count) : 0,
            'cancelledCount' => (int) ($cancelled->quantity ?? 0),
            'cancelledCents' => (int) ($cancelled->value ?? 0),
            'discountCents' => (int) ($orders->discount ?? 0) + (int) ($tabs->discount ?? 0),
            'serviceFeeCents' => (int) ($tabs->service ?? 0),
            'deliveryFeeCents' => (int) ($orders->delivery ?? 0),
        ];
    }

    /**
     * @return list<array{date: string, revenueCents: int, salesCount: int}>
     */
    public function daily(): array
    {
        $byDate = array_fill_keys($this->period->dates(), ['revenueCents' => 0, 'salesCount' => 0]);

        foreach ($this->sales() as $sale) {
            $date = $sale['at']->setTimezone($this->period->timezone)->format('Y-m-d');

            if (isset($byDate[$date])) {
                $byDate[$date]['revenueCents'] += $sale['total'];
                $byDate[$date]['salesCount']++;
            }
        }

        $rows = [];

        foreach ($byDate as $date => $row) {
            $rows[] = ['date' => $date, ...$row];
        }

        return $rows;
    }

    /**
     * Sales per hour of the day, to see the rush hours.
     *
     * @return list<array{hour: int, salesCount: int, revenueCents: int}>
     */
    public function hourly(): array
    {
        $hours = array_fill(0, 24, ['salesCount' => 0, 'revenueCents' => 0]);

        foreach ($this->sales() as $sale) {
            $hour = (int) $sale['at']->setTimezone($this->period->timezone)->format('G');
            $hours[$hour]['salesCount']++;
            $hours[$hour]['revenueCents'] += $sale['total'];
        }

        $rows = [];

        foreach ($hours as $hour => $row) {
            $rows[] = ['hour' => $hour, ...$row];
        }

        return $rows;
    }

    /**
     * @return list<array{key: string, label: string, salesCount: int, revenueCents: int}>
     */
    public function channels(): array
    {
        $labels = [
            'delivery' => 'Entrega',
            'pickup' => 'Retirada / balcão',
            'tables' => 'Mesas',
        ];
        $rows = [];

        foreach ($labels as $key => $label) {
            $sales = $this->sales()->where('channel', $key);
            $rows[] = ['key' => $key, 'label' => $label, 'salesCount' => $sales->count(), 'revenueCents' => (int) $sales->sum('total')];
        }

        $origin = $this->completedOrders()
            ->selectRaw('channel, count(*) as quantity, coalesce(sum(total_cents), 0) as value')
            ->groupBy('channel')
            ->toBase()
            ->get()
            ->keyBy('channel');

        foreach ([OrderChannel::Online, OrderChannel::Pos] as $channel) {
            $row = $origin->get($channel->value);
            $rows[] = [
                'key' => 'origin_'.$channel->value,
                'label' => $channel->label(),
                'salesCount' => (int) ($row->quantity ?? 0),
                'revenueCents' => (int) ($row->value ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * What came into the cash register, per payment method (refunds discounted).
     *
     * @return list<array{method: string, label: string, cents: int}>
     */
    public function payments(): array
    {
        $net = CashMovement::query()
            ->where('store_id', $this->store->id)
            ->whereIn('type', [CashMovementType::Sale, CashMovementType::Refund])
            ->whereBetween('created_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->selectRaw('payment_method, type, coalesce(sum(amount_cents), 0) as value')
            ->groupBy('payment_method', 'type')
            ->toBase()
            ->get()
            ->reduce(function (array $carry, object $row): array {
                $sign = $row->type === CashMovementType::Refund->value ? -1 : 1;
                $carry[$row->payment_method] = ($carry[$row->payment_method] ?? 0) + $sign * (int) $row->value;

                return $carry;
            }, []);

        $rows = [];

        foreach (PaymentMethod::cases() as $method) {
            $rows[] = ['method' => $method->value, 'label' => $method->label(), 'cents' => $net[$method->value] ?? 0];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, quantity: int, revenueCents: int}>
     */
    public function topProducts(int $limit = 10): array
    {
        return array_values(OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $this->store->id)
            ->where('orders.status', OrderStatus::Completed)
            ->whereBetween('orders.placed_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->selectRaw('order_items.product_name as name, sum(order_items.quantity) as quantity, sum(order_items.total_cents) as revenue')
            ->groupBy('order_items.product_name')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['name' => (string) $row->name, 'quantity' => (int) $row->quantity, 'revenueCents' => (int) $row->revenue])
            ->all());
    }

    /**
     * @return list<array{name: string, phone: string|null, ordersCount: int, revenueCents: int}>
     */
    public function topCustomers(int $limit = 10): array
    {
        return array_values($this->completedOrders()
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, max(customer_name) as name, max(customer_phone) as phone, count(*) as quantity, sum(total_cents) as revenue')
            ->groupBy('customer_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'name' => (string) $row->name,
                'phone' => $row->phone !== null ? (string) $row->phone : null,
                'ordersCount' => (int) $row->quantity,
                'revenueCents' => (int) $row->revenue,
            ])
            ->all());
    }

    /**
     * @return list<array{code: string, uses: int, discountCents: int}>
     */
    public function coupons(): array
    {
        return array_values($this->completedOrders()
            ->whereNotNull('coupon_code')
            ->selectRaw('coupon_code, count(*) as uses, sum(discount_cents) as discount')
            ->groupBy('coupon_code')
            ->orderByDesc('uses')
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['code' => (string) $row->coupon_code, 'uses' => (int) $row->uses, 'discountCents' => (int) $row->discount])
            ->all());
    }

    /**
     * Orders of the period for the spreadsheet export (all statuses).
     *
     * @return \Generator<int, Order>
     */
    public function ordersForExport(): \Generator
    {
        foreach ($this->ordersPlaced()->with('tab.table')->orderBy('placed_at')->lazyById(500) as $order) {
            yield $order;
        }
    }

    /**
     * Completed sales outside tables, placed in the period.
     *
     * @return Builder<Order>
     */
    private function completedOrders(): Builder
    {
        return $this->ordersPlaced()->where('status', OrderStatus::Completed)->whereNull('tab_id');
    }

    /**
     * @return Builder<Order>
     */
    private function ordersPlaced(): Builder
    {
        return Order::query()
            ->where('store_id', $this->store->id)
            ->whereBetween('placed_at', [$this->period->startUtc(), $this->period->endUtc()]);
    }

    /**
     * @return Builder<Tab>
     */
    private function closedTabs(): Builder
    {
        return Tab::query()
            ->where('store_id', $this->store->id)
            ->where('status', TabStatus::Closed)
            ->whereBetween('closed_at', [$this->period->startUtc(), $this->period->endUtc()]);
    }

    /**
     * Every sale of the period with its time, value and channel, for the charts.
     *
     * @return Collection<int, array{at: CarbonImmutable, total: int, channel: string}>
     */
    private function sales(): Collection
    {
        if ($this->sales !== null) {
            return $this->sales;
        }

        $orders = $this->completedOrders()
            ->toBase()
            ->get(['placed_at', 'total_cents', 'fulfillment'])
            ->map(fn (object $row) => [
                'at' => CarbonImmutable::parse((string) $row->placed_at, 'UTC'),
                'total' => (int) $row->total_cents,
                'channel' => $row->fulfillment === Fulfillment::Delivery->value ? 'delivery' : 'pickup',
            ]);

        $tabs = $this->closedTabs()
            ->toBase()
            ->get(['closed_at', 'total_cents'])
            ->map(fn (object $row) => [
                'at' => CarbonImmutable::parse((string) $row->closed_at, 'UTC'),
                'total' => (int) $row->total_cents,
                'channel' => 'tables',
            ]);

        return $this->sales = $orders->concat($tabs)->values();
    }
}
