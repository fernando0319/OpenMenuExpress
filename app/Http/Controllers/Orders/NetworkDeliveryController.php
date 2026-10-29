<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\NetworkDelivery;
use App\Models\Order;
use App\Models\User;
use App\Services\CourierNetwork\Dispatcher;
use App\Support\NetworkSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Calling a courier of the network for an order, and calling it off.
 */
class NetworkDeliveryController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function store(Request $request, Order $order): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($order);

        $data = $request->validate(['fee_cents' => ['required', 'integer', 'min:1', 'max:100000']]);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->dispatcher->call($order, (int) $data['fee_cents'], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Procurando um entregador perto da loja…']);

        return back();
    }

    public function destroy(Order $order): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($order);

        $delivery = $this->dispatcher->activeFor($order);
        abort_unless($delivery instanceof NetworkDelivery, 404);

        $this->dispatcher->cancelByStore($delivery);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Corrida cancelada, sem cobrança.']);

        return back();
    }

    /**
     * The network panel of the order page.
     *
     * @return array<string, mixed>
     */
    public static function panel(Order $order, Dispatcher $dispatcher): array
    {
        $delivery = $dispatcher->activeFor($order) ?? NetworkDelivery::query()->where('order_id', $order->id)->latest('id')->first();
        $courier = $delivery?->courier;

        return [
            'blockedReason' => $dispatcher->blockedReason($order),
            'defaultFeeCents' => $order->store->network_fee_cents ?? $order->delivery_fee_cents,
            // So the store knows beforehand whether anyone can come.
            'nearby' => $dispatcher->availableNear($order->store),
            'radiusKm' => NetworkSettings::all()['search_radius_km'],
            'delivery' => $delivery === null ? null : [
                'id' => $delivery->id,
                'status' => $delivery->status->value,
                'statusLabel' => $delivery->status->label(),
                'active' => $delivery->status->isActive(),
                'feeCents' => $delivery->fee_cents,
                'searchingSince' => $delivery->searching_since->toIso8601String(),
                'cancelReason' => $delivery->cancel_reason,
                'courier' => $courier === null ? null : [
                    'name' => $courier->user->name,
                    'phone' => $courier->phone,
                    'vehicle' => $courier->vehicleLabel(),
                ],
            ],
        ];
    }
}
