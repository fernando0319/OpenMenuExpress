<?php

namespace App\Jobs;

use App\Enums\NetworkDeliveryStatus;
use App\Models\NetworkCourier;
use App\Models\NetworkDelivery;
use App\Services\CourierNetwork\Dispatcher;
use App\Services\Push\PushNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Wakes up the phones of the couriers a run is offered to. It runs when the store calls and
 * again as the search circle grows, telling only couriers not told before.
 */
class OfferRunToCouriers implements ShouldQueue
{
    use Queueable;

    /** Seconds after the call when the circle grows (see Dispatcher::searchRadiusKm). */
    public const WAVES = [0, 31, 61];

    public function __construct(public readonly int $deliveryId)
    {
        $this->afterCommit();
    }

    public function handle(Dispatcher $dispatcher, PushNotifications $push): void
    {
        $delivery = NetworkDelivery::query()->with(['store', 'order'])->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== NetworkDeliveryStatus::Searching) {
            return;
        }

        $couriers = $dispatcher->couriersToNotify($delivery);

        if ($couriers->isEmpty()) {
            return;
        }

        DB::table('network_delivery_pushes')->insertOrIgnore($couriers->map(fn (NetworkCourier $courier) => [
            'network_delivery_id' => $delivery->id,
            'network_courier_id' => $courier->id,
            'created_at' => now(),
        ])->all());

        $store = $delivery->store;
        $from = $store->address_district !== null ? "{$store->name} ({$store->address_district})" : $store->name;
        $to = $delivery->order->delivery_district ?? $delivery->order->delivery_city ?? 'endereço do cliente';

        $push->toUsers(
            array_values($couriers->map(fn (NetworkCourier $courier) => $courier->user_id)->all()),
            'Nova corrida · R$ '.number_format($delivery->courier_cents / 100, 2, ',', '.'),
            "{$from} → {$to}. Toque para ver e aceitar.",
            '/entregador',
            "offer-{$delivery->id}",
        );
    }
}
