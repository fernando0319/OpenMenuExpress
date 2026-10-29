<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PosOrderRequest;
use App\Models\User;
use App\Services\Cash\CashRegister;
use App\Services\Catalog\MenuCatalog;
use App\Services\Delivery\DeliveryTracking;
use App\Services\Orders\PlacePosOrder;
use App\Services\Payments\StorePaymentMethods;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Counter sales: pick products, take the payment, done.
 */
class PosController extends Controller
{
    use InteractsWithCurrentStore;

    public function show(MenuCatalog $catalog, CashRegister $register, StorePaymentMethods $paymentMethods, DeliveryTracking $tracking): Response
    {
        $store = $this->currentStore();

        return Inertia::render('pos/Index', [
            'categories' => $catalog->for($store),
            'deliveryZones' => $store->deliveryZones()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'fee_cents']),
            'paymentMethods' => $paymentMethods->options($store),
            'cashOpen' => $register->current($store) !== null,
            'couriers' => $tracking->candidates($store),
        ]);
    }

    public function store(PosOrderRequest $request, PlacePosOrder $placeOrder): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = $placeOrder->handle($this->currentStore(), $request->posInput(), $user);

        $change = $order->change_for_cents !== null && $order->paid_at !== null
            ? $order->change_for_cents - $order->total_cents
            : 0;

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $change > 0
                ? "Venda nº {$order->number} registrada. Troco: R$ ".Money::toInput($change).'.'
                : "Venda nº {$order->number} registrada.",
        ]);
        Inertia::flash('sale', ['id' => $order->id, 'number' => $order->number, 'changeCents' => $change]);

        return back();
    }
}
