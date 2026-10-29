<?php

namespace App\Http\Controllers\Courier;

use App\Enums\NetworkDeliveryStatus;
use App\Enums\PixKeyType;
use App\Enums\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Courier\Concerns\InteractsWithCourier;
use App\Http\Requests\Courier\CourierProfileRequest;
use App\Models\CourierPayout;
use App\Models\NetworkCourier;
use App\Models\NetworkDelivery;
use App\Services\CourierNetwork\Dispatcher;
use App\Services\Push\PushNotifications;
use App\Support\NetworkSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The courier's app on the phone: going online, the runs on offer, the run in progress,
 * earnings and profile.
 */
class CourierAppController extends Controller
{
    use InteractsWithCourier;

    public function home(Request $request, Dispatcher $dispatcher): Response
    {
        $courier = $this->courier($request);
        $active = $courier->isApproved() ? $courier->activeDelivery() : null;
        $weekStart = CarbonImmutable::now(NetworkSettings::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        $dayStart = CarbonImmutable::now(NetworkSettings::TIMEZONE)->startOfDay();

        return Inertia::render('courier/Home', [
            'courier' => $this->courierPayload($courier),
            'networkEnabled' => NetworkSettings::enabled(),
            'pushKey' => PushNotifications::publicKey(),
            'active' => fn () => $active === null ? null : $this->activePayload($active->load(['store', 'order'])),
            'offers' => fn () => $active === null ? array_map(fn (array $offer) => [
                'id' => $offer['delivery']->id,
                'storeName' => $offer['delivery']->store->name,
                'storeDistrict' => $offer['delivery']->store->address_district,
                'distanceKm' => $offer['distanceKm'],
                'dropDistrict' => $offer['delivery']->order->delivery_district,
                'dropCity' => $offer['delivery']->order->delivery_city,
                'routeKm' => $offer['delivery']->order->delivery_distance_km !== null ? (float) $offer['delivery']->order->delivery_distance_km : null,
                'courierCents' => $offer['delivery']->courier_cents,
                'collect' => $this->collect($offer['delivery']->order),
            ], $dispatcher->offersFor($courier)) : [],
            'today' => fn () => $this->earned($courier, $dayStart),
            'week' => fn () => $this->earned($courier, $weekStart),
        ]);
    }

    public function online(Request $request): RedirectResponse
    {
        $data = $request->validate(['online' => ['required', 'boolean']]);
        $courier = $this->approvedCourier($request);
        $online = (bool) $data['online'];

        if ($online && ! NetworkSettings::enabled()) {
            throw ValidationException::withMessages(['online' => 'A rede de entregadores não está disponível no momento.']);
        }

        if (! $online && $courier->activeDelivery() !== null) {
            throw ValidationException::withMessages(['online' => 'Termine a corrida atual antes de ficar offline.']);
        }

        $courier->update(['is_online' => $online]);

        return back();
    }

    /**
     * Receives the phone's position while online.
     */
    public function location(Request $request, Dispatcher $dispatcher): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $courier = $this->courier($request);

        if (! $courier->isApproved()) {
            return response()->json(['tracking' => false]);
        }

        $dispatcher->locate(
            $courier,
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null,
        );

        return response()->json(['tracking' => true]);
    }

    public function earnings(Request $request): Response
    {
        $courier = $this->courier($request);

        $unpaid = $courier->deliveries()->where('status', NetworkDeliveryStatus::Delivered)->whereNull('courier_payout_id');

        return Inertia::render('courier/Earnings', [
            'courier' => $this->courierPayload($courier),
            'open' => ['count' => (clone $unpaid)->count(), 'amountCents' => (int) $unpaid->sum('courier_cents')],
            'payouts' => $courier->payouts()->orderByDesc('period_start')->limit(12)->get()->map(fn (CourierPayout $payout) => [
                'id' => $payout->id,
                'period' => $payout->periodLabel(),
                'count' => $payout->deliveries_count,
                'amountCents' => $payout->amount_cents,
                'status' => $payout->status,
                'paidAt' => $payout->paid_at?->toIso8601String(),
            ]),
            'runs' => $courier->deliveries()
                ->where('status', NetworkDeliveryStatus::Delivered)
                ->with('store:id,name')
                ->latest('delivered_at')
                ->limit(30)
                ->get()
                ->map(fn (NetworkDelivery $delivery) => [
                    'id' => $delivery->id,
                    'storeName' => $delivery->store->name,
                    'deliveredAt' => $delivery->delivered_at?->toIso8601String(),
                    'courierCents' => $delivery->courier_cents,
                ]),
        ]);
    }

    public function profile(Request $request): Response
    {
        $courier = $this->courier($request);

        return Inertia::render('courier/Profile', [
            'courier' => [
                ...$this->courierPayload($courier),
                'email' => $courier->user->email,
                'cpf' => $courier->cpf,
                'phone' => $courier->phone,
                'city' => $courier->city,
                'state' => $courier->state,
                'vehicleValue' => $courier->vehicle->value,
                'vehiclePlate' => $courier->vehicle_plate,
                'pixKeyType' => $courier->pix_key_type->value,
                'pixKey' => $courier->pix_key,
            ],
            ...self::formOptions(),
        ]);
    }

    public function updateProfile(CourierProfileRequest $request): RedirectResponse
    {
        $this->courier($request)->update($request->profile());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dados atualizados.']);

        return back();
    }

    /**
     * Choices of the courier forms.
     *
     * @return array{vehicles: list<array{value: string, label: string}>, pixKeyTypes: list<array{value: string, label: string}>, states: list<string>}
     */
    public static function formOptions(): array
    {
        return [
            'vehicles' => array_map(fn (Vehicle $vehicle) => ['value' => $vehicle->value, 'label' => $vehicle->label()], Vehicle::cases()),
            'pixKeyTypes' => array_map(fn (PixKeyType $type) => ['value' => $type->value, 'label' => $type->label()], PixKeyType::cases()),
            'states' => CourierProfileRequest::STATES,
        ];
    }

    /**
     * @return array{name: string, status: string, statusLabel: string, statusReason: string|null, isOnline: bool, vehicle: string}
     */
    private function courierPayload(NetworkCourier $courier): array
    {
        return [
            'name' => $courier->user->name,
            'status' => $courier->status->value,
            'statusLabel' => $courier->status->label(),
            'statusReason' => $courier->status_reason,
            'isOnline' => $courier->is_online,
            'vehicle' => $courier->vehicleLabel(),
        ];
    }

    /**
     * @return array{count: int, amountCents: int}
     */
    private function earned(NetworkCourier $courier, CarbonImmutable $since): array
    {
        $query = $courier->deliveries()->where('status', NetworkDeliveryStatus::Delivered)->where('delivered_at', '>=', $since->utc());

        return ['count' => (clone $query)->count(), 'amountCents' => (int) $query->sum('courier_cents')];
    }
}
