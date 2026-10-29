<?php

namespace App\Http\Controllers\Orders;

use App\Enums\Fulfillment;
use App\Enums\Module;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\EditOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Tab;
use App\Models\User;
use App\Services\Billing\Subscription;
use App\Services\Cash\CashRegister;
use App\Services\Catalog\MenuCatalog;
use App\Services\Orders\EditOrder;
use App\Services\Payments\StorePaymentMethods;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fixing an order (wrong item, forgotten extra, new address) without cancelling it.
 */
class OrderEditController extends Controller
{
    use InteractsWithCurrentStore;

    public function edit(Order $order, MenuCatalog $catalog, CashRegister $register, StorePaymentMethods $paymentMethods): Response|RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($order);
        $store = $this->currentStore();

        if (! $order->isEditable()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "O pedido nº {$order->number} já foi finalizado e não pode mais ser alterado."]);

            return to_route('orders.show', $order);
        }

        $order->load('items.options');
        $tab = $order->tab_id !== null ? Tab::query()->with('table')->find($order->tab_id) : null;

        return Inertia::render('orders/Edit', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'statusLabel' => $order->status->label(),
                'fulfillment' => $order->fulfillment->value,
                'fulfillmentLabel' => $order->fulfillment->label(),
                'tableName' => $tab?->table?->name,
                'tableId' => $tab?->dining_table_id,
                'customerName' => $order->customer_name,
                'customerPhone' => $order->customer_phone,
                'address' => [
                    'zip' => $order->delivery_zip ?? '',
                    'street' => $order->delivery_street ?? '',
                    'number' => $order->delivery_number ?? '',
                    'district' => $order->delivery_district ?? '',
                    'complement' => $order->delivery_complement ?? '',
                    'reference' => $order->delivery_reference ?? '',
                ],
                'deliveryFee' => Money::toInput($order->delivery_fee_cents),
                'discount' => $order->discount_cents > 0 ? Money::toInput($order->discount_cents) : '',
                'couponCode' => $order->coupon_code,
                'paymentMethod' => $order->payment_method,
                'paymentLabel' => $order->paymentLabel(),
                'changeFor' => $order->change_for_cents !== null ? Money::toInput($order->change_for_cents) : '',
                'notes' => $order->notes ?? '',
                'totalCents' => $order->total_cents,
                'receivedCents' => $order->tab_id === null ? $register->receivedCents($order) : 0,
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unitCents' => $item->unit_price_cents + $item->options_price_cents,
                    'notes' => $item->notes ?? '',
                    'options' => $item->options->map(fn (OrderItemOption $option) => ($option->quantity > 1 ? "{$option->quantity}x " : '').$option->option_name)->values(),
                ])->values(),
            ],
            'categories' => $catalog->for($store),
            'paymentMethods' => $paymentMethods->options($store),
            'cashOpen' => $register->current($store) !== null,
            'canNotify' => $order->tab_id === null && $store->whatsapp_enabled && Subscription::for($store)->hasModule(Module::WhatsApp),
        ]);
    }

    public function update(EditOrderRequest $request, Order $order, EditOrder $editOrder): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($order);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $change = $editOrder->handle($order, $request->editInput(), $user);

        $message = "Pedido nº {$order->number} alterado.";

        if ($change->refunded_cents > 0) {
            $message .= ' Estorno de R$ '.Money::toInput($change->refunded_cents).' lançado no caixa.';
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        if ($order->tab_id !== null && $order->fulfillment === Fulfillment::DineIn) {
            $tableId = Tab::query()->whereKey($order->tab_id)->value('dining_table_id');

            if ($tableId !== null) {
                return to_route('tables.show', $tableId);
            }
        }

        return to_route('orders.show', $order);
    }
}
