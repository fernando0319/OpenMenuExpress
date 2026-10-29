<?php

namespace App\Services\Delivery;

use App\Enums\Fulfillment;
use App\Enums\NetworkDeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\CourierLocation;
use App\Models\NetworkDelivery;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Who delivers each order and where the courier is while delivering.
 *
 * Positions are only accepted while the courier has an order "out for delivery",
 * and only the latest recent one is ever shown, to the store and to that order's customer.
 */
final class DeliveryTracking
{
    /** Positions closer in time than this are skipped, to keep the table small. */
    private const MIN_SECONDS_BETWEEN_POINTS = 5;

    public function assign(Order $order, ?User $courier): void
    {
        if ($order->fulfillment !== Fulfillment::Delivery || $order->status->isFinal()) {
            throw ValidationException::withMessages(['courier_id' => 'Só pedidos de entrega em andamento recebem entregador.']);
        }

        $networkRun = NetworkDelivery::query()
            ->where('order_id', $order->id)
            ->whereIn('status', NetworkDeliveryStatus::active())
            ->exists();

        if ($networkRun) {
            throw ValidationException::withMessages(['courier_id' => 'Este pedido está com a rede de entregadores. Cancele a corrida antes.']);
        }

        if ($courier !== null && ! $this->isMember($order->store_id, $courier)) {
            throw ValidationException::withMessages(['courier_id' => 'Escolha alguém da equipe.']);
        }

        $order->update(['courier_id' => $courier?->id]);
    }

    /**
     * @return bool Whether the position was stored.
     */
    public function record(Store $store, User $courier, float $latitude, float $longitude, ?int $accuracy, ?CarbonImmutable $at = null): bool
    {
        if (! $this->hasActiveDelivery($store, $courier)) {
            return false;
        }

        $at ??= CarbonImmutable::now();

        $last = CourierLocation::query()
            ->where('store_id', $store->id)
            ->where('user_id', $courier->id)
            ->latest('recorded_at')
            ->first();

        if ($last !== null && $last->recorded_at->diffInSeconds($at, true) < self::MIN_SECONDS_BETWEEN_POINTS) {
            return true;
        }

        CourierLocation::query()->create([
            'store_id' => $store->id,
            'user_id' => $courier->id,
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'accuracy_m' => $accuracy !== null ? min($accuracy, 65535) : null,
            'recorded_at' => $at,
        ]);

        return true;
    }

    public function hasActiveDelivery(Store $store, User $courier): bool
    {
        return $store->orders()
            ->where('courier_id', $courier->id)
            ->where('status', OrderStatus::OutForDelivery)
            ->exists();
    }

    /**
     * Latest recent position of each given courier, keyed by user id.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, CourierLocation>
     */
    public function latestPositions(Store $store, array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return CourierLocation::query()
            ->where('store_id', $store->id)
            ->whereIn('user_id', $userIds)
            ->where('recorded_at', '>=', now()->subMinutes(CourierLocation::LIVE_MINUTES))
            ->orderByDesc('recorded_at')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');
    }

    /**
     * What the customer may see on the order page: the courier's first name and live position,
     * only while the order is on its way.
     *
     * @return array{courierName: string, position: array{lat: float, lng: float, accuracy: int|null, at: string}|null}|null
     */
    public function forCustomer(Order $order): ?array
    {
        if ($order->status !== OrderStatus::OutForDelivery || $order->courier_id === null) {
            return null;
        }

        $courier = $order->courier;

        if (! $courier instanceof User) {
            return null;
        }

        $position = $this->latestPositions($order->store, [$courier->id])->get($courier->id);

        return [
            'courierName' => explode(' ', trim($courier->name))[0],
            'position' => $position?->toPoint(),
        ];
    }

    /**
     * Members who can take deliveries, couriers first.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    public function candidates(Store $store): array
    {
        return array_values(StoreMember::query()
            ->where('store_id', $store->id)
            ->with('user:id,name')
            ->get()
            ->filter(fn (StoreMember $membership) => $membership->user instanceof User)
            ->sortBy(fn (StoreMember $membership) => [$membership->role->value === 'courier' ? 0 : 1, $membership->user->name ?? ''])
            ->map(fn (StoreMember $membership) => [
                'id' => $membership->user_id,
                'name' => $membership->user->name ?? '',
                'role' => $membership->role->label(),
            ])
            ->values()
            ->all());
    }

    /**
     * Deletes positions past the retention period.
     */
    public function prune(): int
    {
        return CourierLocation::query()
            ->where('recorded_at', '<', now()->subDays(CourierLocation::RETENTION_DAYS))
            ->delete();
    }

    private function isMember(int $storeId, User $user): bool
    {
        return StoreMember::query()->where('store_id', $storeId)->where('user_id', $user->id)->exists();
    }
}
