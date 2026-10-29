<?php

namespace App\Http\Controllers\Orders;

use App\Enums\Fulfillment;
use App\Enums\NetworkDeliveryStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\ReceivePaymentRequest;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\OrderStatusChange;
use App\Models\User;
use App\Printing\PrintQueue;
use App\Services\Cash\CashRegister;
use App\Services\Cash\Settlement;
use App\Services\CourierNetwork\Dispatcher;
use App\Services\Delivery\DeliveryTracking;
use App\Services\Orders\ChangeOrderStatus;
use App\Services\Payments\StorePaymentMethods;
use App\Support\Money;
use App\Support\NetworkSettings;
use App\Support\SlotLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    use InteractsWithCurrentStore;

    /**
     * "open" (the default) shows orders still in progress; any status value filters by it.
     */
    public function index(Request $request, DeliveryTracking $tracking, Dispatcher $dispatcher): Response
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'all', ...array_column(OrderStatus::cases(), 'value')])],
        ])['status'] ?? 'open';

        // Orders waiting for an online payment are not the store's yet.
        $finalStatuses = [
            ...array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->isFinal()),
            OrderStatus::PendingPayment,
        ];

        $orders = $this->currentStore()->orders()
            ->when($filter === 'open', fn ($query) => $query->whereNotIn('status', $finalStatuses))
            ->when(! in_array($filter, ['open', 'all'], true), fn ($query) => $query->where('status', $filter))
            ->latest('placed_at')
            ->latest('id')
            ->with('courier:id,name')
            ->withExists(['networkDeliveries as network_active' => fn ($query) => $query->whereIn('status', NetworkDeliveryStatus::active())])
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'customerName' => $order->customer_name,
                'fulfillment' => $order->fulfillment->label(),
                'isDelivery' => $order->fulfillment === Fulfillment::Delivery,
                'courierId' => $order->courier_id,
                'courierName' => $order->courier?->name,
                'networkActive' => (bool) $order->getAttribute('network_active'),
                'deliveryFeeCents' => $order->delivery_fee_cents,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'nextAction' => $this->mainNextStatus($order),
                'totalCents' => $order->total_cents,
                'placedAt' => $order->placed_at->toIso8601String(),
                'scheduledFor' => SlotLabel::describe($order->scheduled_for, $this->currentStore()->timezone, CarbonImmutable::now()),
            ]);

        return Inertia::render('orders/Index', [
            'orders' => $orders,
            'pendingCount' => $this->currentStore()->orders()->where('status', OrderStatus::Pending)->count(),
            'statuses' => array_map(
                fn (OrderStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                OrderStatus::cases(),
            ),
            'filters' => ['status' => $filter],
            'couriers' => $tracking->candidates($this->currentStore()),
            // Calling the network from the "out for delivery" shortcut, when the store can.
            'network' => Gate::allows('operate-pos') && $dispatcher->storeBlockedReason($this->currentStore()) === null ? [
                'feeCents' => $this->currentStore()->network_fee_cents,
                'nearby' => $dispatcher->availableNear($this->currentStore()),
                'radiusKm' => NetworkSettings::all()['search_radius_km'],
            ] : null,
        ]);
    }

    public function show(Order $order, CashRegister $register, StorePaymentMethods $paymentMethods, DeliveryTracking $tracking, Dispatcher $dispatcher): Response
    {
        $this->ensureBelongsToCurrentStore($order);

        $order->load(['items.options', 'statusHistory.user', 'courier:id,name', 'changes.user']);
        $receivedCents = $register->receivedCents($order);

        return Inertia::render('orders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'nextStatuses' => array_map(
                    fn (OrderStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                    $order->status->nextFor($order->fulfillment),
                ),
                'channel' => $order->channel->label(),
                'fulfillment' => $order->fulfillment->label(),
                'placedAt' => $order->placed_at->toIso8601String(),
                'scheduledFor' => SlotLabel::describe($order->scheduled_for, $this->currentStore()->timezone, CarbonImmutable::now()),
                'customerName' => $order->customer_name,
                'customerPhone' => $order->customer_phone,
                'address' => $order->delivery_street === null ? null : [
                    'street' => $order->delivery_street,
                    'number' => $order->delivery_number,
                    'district' => $order->delivery_district,
                    'complement' => $order->delivery_complement,
                    'reference' => $order->delivery_reference,
                    'zip' => $order->delivery_zip,
                ],
                'paymentMethod' => $order->paymentLabel(),
                'paidAt' => $order->paid_at?->toIso8601String(),
                'courierId' => $order->courier_id,
                'courierName' => $order->courier?->name,
                'canAssignCourier' => $order->fulfillment === Fulfillment::Delivery && ! $order->status->isFinal(),
                'payments' => $this->payments($order),
                'canReceive' => $order->paid_at === null
                    && $order->tab_id === null
                    && $order->status !== OrderStatus::Cancelled
                    && Gate::allows('operate-pos')
                    && $register->current($this->currentStore()) !== null,
                'changeForCents' => $order->change_for_cents,
                'notes' => $order->notes,
                'subtotalCents' => $order->subtotal_cents,
                'deliveryFeeCents' => $order->delivery_fee_cents,
                'discountCents' => $order->discount_cents,
                'couponCode' => $order->coupon_code,
                'totalCents' => $order->total_cents,
                'tabId' => $order->tab_id,
                'canEdit' => $order->isEditable(),
                'receivedCents' => $receivedCents,
                // After an edit: more to receive (positive) or to give back (negative).
                'balanceCents' => $order->tab_id === null && $receivedCents > 0 ? $order->total_cents - $receivedCents : 0,
                'changes' => $order->changes->reverse()->values()->map(fn (OrderChange $change) => [
                    'id' => $change->id,
                    'by' => $change->user?->name,
                    'at' => $change->created_at?->toIso8601String(),
                    'reason' => $change->reason,
                    'lines' => [...$change->itemLines(), ...$change->fields],
                    'totalBeforeCents' => $change->total_before_cents,
                    'totalAfterCents' => $change->total_after_cents,
                    'refundedCents' => $change->refunded_cents,
                ]),
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unitCents' => $item->unit_price_cents + $item->options_price_cents,
                    'totalCents' => $item->total_cents,
                    'notes' => $item->notes,
                    'options' => $item->options->map(fn (OrderItemOption $option) => [
                        'group' => $option->group_name,
                        'name' => $option->option_name,
                        'quantity' => $option->quantity,
                        'priceCents' => $option->price_cents,
                    ]),
                ]),
                'history' => $order->statusHistory->map(fn (OrderStatusChange $change) => [
                    'id' => $change->id,
                    'status' => $change->to_status->label(),
                    'by' => $change->user instanceof User ? $change->user->name : null,
                    'at' => $change->created_at?->toIso8601String(),
                ]),
                'messages' => $this->currentStore()->messageLogs()
                    ->where('order_id', $order->id)
                    ->latest('id')
                    ->get()
                    ->map(fn (MessageLog $log) => [
                        'id' => $log->id,
                        'event' => $log->event->label(),
                        'status' => $log->status,
                        'error' => $log->error,
                        'at' => $log->created_at->toIso8601String(),
                    ]),
            ],
            'paymentMethods' => $paymentMethods->options($this->currentStore()),
            'couriers' => $order->fulfillment === Fulfillment::Delivery ? $tracking->candidates($this->currentStore()) : [],
            'network' => $order->fulfillment === Fulfillment::Delivery && Gate::allows('operate-pos') && (NetworkSettings::enabled() || $order->networkDeliveries()->exists())
                ? NetworkDeliveryController::panel($order, $dispatcher)
                : null,
        ]);
    }

    /**
     * Moves the order on. "Out for delivery" may carry the courier chosen at that moment.
     */
    public function updateStatus(Request $request, Order $order, ChangeOrderStatus $changeStatus, DeliveryTracking $tracking): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($order);

        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'courier_id' => ['nullable', 'integer'],
        ]);

        $to = OrderStatus::from($data['status']);
        $user = $request->user();

        // Only when the move is valid, so a refused change does not leave a courier behind.
        if ($to === OrderStatus::OutForDelivery && isset($data['courier_id']) && $order->status->canMoveTo($to, $order->fulfillment)) {
            $courier = User::query()->whereKey($data['courier_id'])->first();

            if ($courier === null) {
                throw ValidationException::withMessages(['courier_id' => 'Escolha alguém da equipe.']);
            }

            $tracking->assign($order, $courier);
        }

        $changeStatus->handle($order, $to, $user instanceof User ? $user : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pedido nº {$order->number}: {$to->label()}."]);

        return back();
    }

    /**
     * Receives an order that was not paid yet (paid on delivery, or the customer came back).
     */
    public function receivePayment(ReceivePaymentRequest $request, Order $order, CashRegister $register): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($order);

        if ($order->tab_id !== null) {
            throw ValidationException::withMessages(['payments' => 'Este pedido é recebido no fechamento da conta da mesa.']);
        }

        $settlement = Settlement::of($register->dueCents($order), $request->payments());

        $user = $request->user();
        $register->receive($order, $settlement, $user instanceof User ? $user : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => $settlement->changeCents > 0
            ? 'Pagamento registrado. Troco: R$ '.Money::toInput($settlement->changeCents).'.'
            : 'Pagamento registrado.']);

        return back();
    }

    public function assignCourier(Request $request, Order $order, DeliveryTracking $tracking): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($order);

        $data = $request->validate(['courier_id' => ['nullable', 'integer']]);
        $courier = isset($data['courier_id']) ? User::query()->whereKey($data['courier_id'])->first() : null;

        if (isset($data['courier_id']) && $courier === null) {
            throw ValidationException::withMessages(['courier_id' => 'Escolha alguém da equipe.']);
        }

        $tracking->assign($order, $courier);

        Inertia::flash('toast', ['type' => 'success', 'message' => $courier !== null
            ? "Pedido nº {$order->number}: entrega com {$courier->name}."
            : "Pedido nº {$order->number}: sem entregador."]);

        return back();
    }

    public function print(Order $order, PrintQueue $queue): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($order);

        $count = $queue->queueOrder($order);

        Inertia::flash('toast', $count > 0
            ? ['type' => 'success', 'message' => "Pedido nº {$order->number} enviado para impressão."]
            : ['type' => 'warning', 'message' => 'Nenhuma impressora ativa. Configure em Impressão.']);

        return back();
    }

    public function printChange(Order $order, OrderChange $change, PrintQueue $queue): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($order);
        abort_unless($change->order_id === $order->id, 404);

        $count = $queue->queueOrderChange($order, $change);

        Inertia::flash('toast', $count > 0
            ? ['type' => 'success', 'message' => "Alteração do pedido nº {$order->number} enviada para impressão."]
            : ['type' => 'warning', 'message' => 'Nenhuma impressora ativa. Configure em Impressão.']);

        return back();
    }

    /**
     * What was received for the order, net of refunds, per method.
     *
     * @return list<array{method: string, cents: int}>
     */
    private function payments(Order $order): array
    {
        $net = [];

        foreach ($order->cashMovements()->whereNotNull('payment_method')->get() as $movement) {
            $label = $movement->payment_method?->label() ?? '';
            $net[$label] = ($net[$label] ?? 0) + $movement->signedCents();
        }

        $rows = [];

        foreach ($net as $method => $cents) {
            $rows[] = ['method' => $method, 'cents' => $cents];
        }

        return $rows;
    }

    /**
     * The usual next step, offered as a one-click button in the list.
     *
     * @return array{value: string, label: string}|null
     */
    private function mainNextStatus(Order $order): ?array
    {
        $next = array_values(array_filter(
            $order->status->nextFor($order->fulfillment),
            fn (OrderStatus $status) => $status !== OrderStatus::Cancelled
                && $status !== OrderStatus::Preparing
                && $status !== OrderStatus::Ready,
        ));

        return $next === [] ? null : ['value' => $next[0]->value, 'label' => $next[0]->label()];
    }
}
