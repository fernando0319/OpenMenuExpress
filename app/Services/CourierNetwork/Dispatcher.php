<?php

namespace App\Services\CourierNetwork;

use App\Enums\CourierStatus;
use App\Enums\Fulfillment;
use App\Enums\NetworkDeliveryStatus;
use App\Enums\OrderStatus;
use App\Jobs\OfferRunToCouriers;
use App\Jobs\SendPushNotification;
use App\Models\NetworkCourier;
use App\Models\NetworkDelivery;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\DeliveryTracking;
use App\Services\Orders\ChangeOrderStatus;
use App\Support\NetworkSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Runs of the courier network, from the store's call to the doorstep.
 *
 * A run is offered to approved couriers online around the store, nearest first: the search
 * circle grows while nobody accepts (see searchRadiusKm), and the first courier to accept takes it.
 * The courier then picks it up (the order goes out for delivery) and delivers it (the order
 * is completed). Only delivered runs are charged to the store and paid to the courier.
 */
final class Dispatcher
{
    /** Order statuses in which a store can call a courier: accepted, not yet on its way. */
    private const CALLABLE = [OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Ready];

    /** Inner search circles: [seconds the run stays in it, radius in km]. */
    private const INNER_CIRCLES = [[30, 3.0], [60, 5.0]];

    /** How many offers a courier sees at once. */
    private const MAX_OFFERS = 5;

    public function __construct(
        private readonly ChangeOrderStatus $changeStatus,
        private readonly DeliveryTracking $tracking,
        private readonly NetworkBilling $billing,
    ) {}

    /**
     * Current search circle of a run. Couriers nearby get the first chance (3 km for 30 s,
     * then 5 km until the first minute), but only when someone is there: an empty inner
     * circle is skipped at once, so a run never waits for nobody.
     */
    public function searchRadiusKm(NetworkDelivery $delivery, ?CarbonImmutable $now = null): float
    {
        $maxKm = NetworkSettings::all()['search_radius_km'];
        $waited = (int) $delivery->searching_since->diffInSeconds($now ?? CarbonImmutable::now(), true);

        foreach (self::INNER_CIRCLES as [$untilSeconds, $km]) {
            if ($waited < $untilSeconds && $km < $maxKm && $this->available($delivery->pickup_latitude, $delivery->pickup_longitude, $km, $delivery)->isNotEmpty()) {
                return $km;
            }
        }

        return $maxKm;
    }

    /**
     * Why the store cannot call the network for this order right now, or null if it can.
     */
    public function blockedReason(Order $order): ?string
    {
        return $this->storeBlockedReason($order->store) ?? match (true) {
            $order->fulfillment !== Fulfillment::Delivery => 'Só pedidos de entrega usam a rede.',
            ! in_array($order->status, self::CALLABLE, true) => $order->status === OrderStatus::Pending || $order->status === OrderStatus::PendingPayment
                ? 'Aceite o pedido antes de chamar um entregador.'
                : 'Este pedido não está mais aguardando entregador.',
            $this->activeFor($order) !== null => 'Já existe uma corrida para este pedido.',
            $order->courier_id !== null => 'O pedido já tem um entregador da equipe. Remova-o antes de chamar a rede.',
            default => null,
        };
    }

    /**
     * Why the store cannot call the network at all right now, whatever the order.
     */
    public function storeBlockedReason(Store $store): ?string
    {
        return match (true) {
            ! NetworkSettings::enabled() => 'A rede de entregadores não está disponível no momento.',
            ! $store->network_enabled => 'Ative a rede de entregadores em "Rede de entregadores".',
            ! $store->hasLocation() => 'Marque a localização da loja no mapa, em "Rede de entregadores".',
            $this->billing->overdueBill($store) !== null => 'Há uma fatura de entregas vencida. Pague para voltar a chamar entregadores.',
            default => null,
        };
    }

    public function activeFor(Order $order): ?NetworkDelivery
    {
        return NetworkDelivery::query()
            ->where('order_id', $order->id)
            ->whereIn('status', NetworkDeliveryStatus::active())
            ->latest('id')
            ->first();
    }

    /**
     * The store calls a courier, paying $feeCents for the run.
     */
    public function call(Order $order, int $feeCents, User $by): NetworkDelivery
    {
        $settings = NetworkSettings::all();

        if ($feeCents < $settings['min_fee_cents']) {
            throw ValidationException::withMessages(['fee' => 'O valor mínimo da corrida é R$ '.number_format($settings['min_fee_cents'] / 100, 2, ',', '.').'.']);
        }

        return DB::transaction(function () use ($order, $feeCents, $by, $settings): NetworkDelivery {
            // Under lock, so a double click never calls two couriers.
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $reason = $this->blockedReason($order);

            if ($reason !== null) {
                throw ValidationException::withMessages(['fee' => $reason]);
            }

            $store = $order->store;

            $delivery = NetworkDelivery::query()->create([
                'store_id' => $store->id,
                'order_id' => $order->id,
                'status' => NetworkDeliveryStatus::Searching,
                'fee_cents' => $feeCents,
                'platform_fee_bps' => $settings['platform_fee_bps'],
                'courier_cents' => NetworkSettings::courierCents($feeCents, $settings['platform_fee_bps']),
                'pickup_latitude' => $store->latitude,
                'pickup_longitude' => $store->longitude,
                'requested_by' => $by->id,
                'searching_since' => now(),
            ]);

            $this->announce($delivery);

            return $delivery;
        });
    }

    /**
     * The store gives up on the run before it is picked up. Free of charge.
     */
    public function cancelByStore(NetworkDelivery $delivery, string $reason = 'Cancelada pela loja'): void
    {
        DB::transaction(function () use ($delivery, $reason): void {
            $current = NetworkDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($current->status === NetworkDeliveryStatus::PickedUp) {
                throw ValidationException::withMessages(['delivery' => 'O entregador já retirou o pedido. Aguarde a entrega.']);
            }

            if (! $current->status->isActive()) {
                return;
            }

            $this->cancel($current, $reason);
        });
    }

    /**
     * Runs waiting for a courier that this one may take, nearest first.
     *
     * @return list<array{delivery: NetworkDelivery, distanceKm: float}>
     */
    public function offersFor(NetworkCourier $courier, ?CarbonImmutable $now = null): array
    {
        if (! $courier->isAvailable() || $courier->activeDelivery() !== null) {
            return [];
        }

        $now ??= CarbonImmutable::now();
        $maxKm = NetworkSettings::all()['search_radius_km'];
        $span = Distance::span((float) $courier->latitude, $maxKm);

        $candidates = NetworkDelivery::query()
            ->where('status', NetworkDeliveryStatus::Searching)
            ->whereBetween('pickup_latitude', [$courier->latitude - $span['lat'], $courier->latitude + $span['lat']])
            ->whereBetween('pickup_longitude', [$courier->longitude - $span['lng'], $courier->longitude + $span['lng']])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('network_delivery_declines')
                ->whereColumn('network_delivery_declines.network_delivery_id', 'network_deliveries.id')
                ->where('network_delivery_declines.network_courier_id', $courier->id))
            ->with(['store', 'order'])
            ->orderBy('searching_since')
            ->limit(50)
            ->get();

        $offers = [];

        foreach ($candidates as $delivery) {
            $distance = Distance::km((float) $courier->latitude, (float) $courier->longitude, $delivery->pickup_latitude, $delivery->pickup_longitude);

            if ($distance <= $maxKm && $distance <= $this->searchRadiusKm($delivery, $now)) {
                $offers[] = ['delivery' => $delivery, 'distanceKm' => round($distance, 1)];
            }
        }

        usort($offers, fn (array $a, array $b) => $a['distanceKm'] <=> $b['distanceKm']);

        return array_slice($offers, 0, self::MAX_OFFERS);
    }

    /**
     * Couriers to wake up for a run still searching: available, free, inside the current
     * search circle, and not told about it (nor turned it down) before.
     *
     * @return Collection<int, NetworkCourier>
     */
    public function couriersToNotify(NetworkDelivery $delivery, ?CarbonImmutable $now = null): Collection
    {
        $told = DB::table('network_delivery_pushes')->where('network_delivery_id', $delivery->id)->pluck('network_courier_id')->all();

        return $this->available($delivery->pickup_latitude, $delivery->pickup_longitude, $this->searchRadiusKm($delivery, $now), $delivery)
            ->reject(fn (NetworkCourier $courier) => in_array($courier->id, $told, true))
            ->values();
    }

    /**
     * Couriers online and free within the search radius of the store right now.
     */
    public function availableNear(Store $store): int
    {
        if (! $store->hasLocation()) {
            return 0;
        }

        return $this->available((float) $store->latitude, (float) $store->longitude, NetworkSettings::all()['search_radius_km'])->count();
    }

    /**
     * Approved couriers, online with a recent position, free, within the radius of a point.
     * For a run, those who turned it down do not count.
     *
     * @return Collection<int, NetworkCourier>
     */
    private function available(float $latitude, float $longitude, float $radiusKm, ?NetworkDelivery $delivery = null): Collection
    {
        $span = Distance::span($latitude, $radiusKm);

        return NetworkCourier::query()
            ->where('status', CourierStatus::Approved)
            ->where('is_online', true)
            ->where('located_at', '>=', now()->subMinutes(NetworkCourier::LOCATION_FRESH_MINUTES))
            ->whereBetween('latitude', [$latitude - $span['lat'], $latitude + $span['lat']])
            ->whereBetween('longitude', [$longitude - $span['lng'], $longitude + $span['lng']])
            ->whereDoesntHave('deliveries', fn ($query) => $query->whereIn('status', [NetworkDeliveryStatus::Accepted, NetworkDeliveryStatus::PickedUp]))
            ->when($delivery !== null, fn ($query) => $query->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('network_delivery_declines')
                ->whereColumn('network_delivery_declines.network_courier_id', 'network_couriers.id')
                ->where('network_delivery_declines.network_delivery_id', $delivery?->id)))
            ->get()
            ->filter(fn (NetworkCourier $courier) => Distance::km((float) $courier->latitude, (float) $courier->longitude, $latitude, $longitude) <= $radiusKm)
            ->values();
    }

    /**
     * The first courier to accept takes the run.
     */
    public function accept(NetworkDelivery $delivery, NetworkCourier $courier): NetworkDelivery
    {
        return DB::transaction(function () use ($delivery, $courier): NetworkDelivery {
            // Courier first, then the run: two runs accepted at once by the same courier must not both pass.
            $courier = NetworkCourier::query()->whereKey($courier->id)->lockForUpdate()->firstOrFail();
            $current = NetworkDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== NetworkDeliveryStatus::Searching) {
                throw ValidationException::withMessages(['delivery' => 'Outro entregador já aceitou esta corrida.']);
            }

            if (! $courier->isAvailable()) {
                throw ValidationException::withMessages(['delivery' => 'Fique online, com a localização ligada, para aceitar corridas.']);
            }

            if ($courier->activeDelivery() !== null) {
                throw ValidationException::withMessages(['delivery' => 'Termine a corrida atual antes de aceitar outra.']);
            }

            $distance = Distance::km((float) $courier->latitude, (float) $courier->longitude, $current->pickup_latitude, $current->pickup_longitude);

            if ($distance > NetworkSettings::all()['search_radius_km']) {
                throw ValidationException::withMessages(['delivery' => 'Você está longe demais desta loja.']);
            }

            $current->update([
                'network_courier_id' => $courier->id,
                'status' => NetworkDeliveryStatus::Accepted,
                'accepted_at' => now(),
            ]);

            // The order shows the courier's name, and the customer's tracking page their position.
            $current->order->update(['courier_id' => $courier->user_id]);

            return $current;
        });
    }

    /**
     * Not interested: the run is not offered to this courier again.
     */
    public function decline(NetworkDelivery $delivery, NetworkCourier $courier): void
    {
        DB::table('network_delivery_declines')->insertOrIgnore([
            'network_delivery_id' => $delivery->id,
            'network_courier_id' => $courier->id,
            'created_at' => now(),
        ]);
    }

    /**
     * The courier gives the run back before picking it up: it is offered to the others again.
     */
    public function giveUp(NetworkDelivery $delivery, NetworkCourier $courier): void
    {
        DB::transaction(function () use ($delivery, $courier): void {
            $current = $this->lockMine($delivery, $courier);

            if ($current->status !== NetworkDeliveryStatus::Accepted) {
                throw ValidationException::withMessages(['delivery' => 'Depois de retirar o pedido, a corrida precisa ser concluída.']);
            }

            $current->update([
                'network_courier_id' => null,
                'status' => NetworkDeliveryStatus::Searching,
                'accepted_at' => null,
                'searching_since' => now(),
            ]);

            Order::query()->whereKey($current->order_id)->where('courier_id', $courier->user_id)->update(['courier_id' => null]);

            $this->decline($current, $courier);

            // Everybody else hears about it again, as a new search.
            DB::table('network_delivery_pushes')->where('network_delivery_id', $current->id)->delete();
            $this->announce($current);
        });
    }

    /**
     * Picked up at the store: the order goes out for delivery.
     */
    public function pickUp(NetworkDelivery $delivery, NetworkCourier $courier): void
    {
        DB::transaction(function () use ($delivery, $courier): void {
            $current = $this->lockMine($delivery, $courier);

            if ($current->status !== NetworkDeliveryStatus::Accepted) {
                throw ValidationException::withMessages(['delivery' => 'Esta corrida já foi retirada.']);
            }

            $current->update(['status' => NetworkDeliveryStatus::PickedUp, 'picked_up_at' => now()]);
        });

        $this->moveOrder($delivery->order()->firstOrFail(), OrderStatus::OutForDelivery, $courier);
    }

    /**
     * Handed to the customer: the order is completed and the run is charged.
     */
    public function complete(NetworkDelivery $delivery, NetworkCourier $courier): void
    {
        DB::transaction(function () use ($delivery, $courier): void {
            $current = $this->lockMine($delivery, $courier);

            if ($current->status !== NetworkDeliveryStatus::PickedUp) {
                throw ValidationException::withMessages(['delivery' => 'Marque a retirada na loja antes.']);
            }

            $current->update(['status' => NetworkDeliveryStatus::Delivered, 'delivered_at' => now()]);
        });

        $this->moveOrder($delivery->order()->firstOrFail(), OrderStatus::Completed, $courier);
    }

    /**
     * Keeps the run in step when the store moves the order by itself.
     */
    public function followOrder(Order $order, OrderStatus $to): void
    {
        $delivery = $this->activeFor($order);

        if ($delivery === null) {
            return;
        }

        $now = now();

        match (true) {
            // The store marked it as gone: the courier has it.
            $to === OrderStatus::OutForDelivery && $delivery->status === NetworkDeliveryStatus::Accepted => $delivery->update([
                'status' => NetworkDeliveryStatus::PickedUp,
                'picked_up_at' => $now,
            ]),
            // Once picked up the run happened, whatever became of the order.
            in_array($to, [OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Refunded], true) && $delivery->status === NetworkDeliveryStatus::PickedUp => $delivery->update([
                'status' => NetworkDeliveryStatus::Delivered,
                'delivered_at' => $now,
            ]),
            $to->isFinal() => $this->cancel($delivery, $to === OrderStatus::Completed ? 'Pedido concluído pela loja' : 'Pedido cancelado'),
            // Nobody took it yet and the order left anyway: someone else is delivering it.
            $to === OrderStatus::OutForDelivery && $delivery->status === NetworkDeliveryStatus::Searching => $this->cancel($delivery, 'Pedido saiu com outro entregador'),
            default => null,
        };
    }

    /**
     * Position sent by the courier's phone. While a run is on its way it also feeds the
     * customer's tracking page.
     */
    public function locate(NetworkCourier $courier, float $latitude, float $longitude, ?int $accuracy): void
    {
        $courier->update([
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'located_at' => now(),
        ]);

        $delivery = $courier->activeDelivery();

        if ($delivery?->status === NetworkDeliveryStatus::PickedUp) {
            $this->tracking->record($delivery->store, $courier->user, $latitude, $longitude, $accuracy);
        }
    }

    private function cancel(NetworkDelivery $delivery, string $reason): void
    {
        $courierUserId = $delivery->courier?->user_id;

        $delivery->update([
            'status' => NetworkDeliveryStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        if ($courierUserId !== null) {
            Order::query()->whereKey($delivery->order_id)->where('courier_id', $courierUserId)->update(['courier_id' => null]);

            // The courier may be on the way to the store.
            SendPushNotification::dispatch(
                [$courierUserId],
                'Corrida cancelada',
                "A loja cancelou a corrida ({$reason}). Não precisa mais ir até lá.",
                '/entregador',
                "run-{$delivery->id}",
            );
        }
    }

    /**
     * Notifies the couriers around the store now and each time the search circle grows.
     */
    private function announce(NetworkDelivery $delivery): void
    {
        foreach (OfferRunToCouriers::WAVES as $seconds) {
            OfferRunToCouriers::dispatch($delivery->id)->delay(now()->addSeconds($seconds));
        }
    }

    private function lockMine(NetworkDelivery $delivery, NetworkCourier $courier): NetworkDelivery
    {
        $current = NetworkDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

        abort_unless($current->network_courier_id === $courier->id, 404);

        return $current;
    }

    /**
     * Moves the order along when it still can (the store may have moved it already).
     */
    private function moveOrder(Order $order, OrderStatus $to, NetworkCourier $courier): void
    {
        if ($order->status !== $to && $order->status->canMoveTo($to, $order->fulfillment)) {
            $this->changeStatus->handle($order, $to, $courier->user);
        }
    }
}
