<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CourierStatus;
use App\Enums\NetworkDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\CourierPayout;
use App\Models\DeliveryBill;
use App\Models\NetworkCourier;
use App\Models\NetworkDelivery;
use App\Support\NetworkSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The courier network at a glance, its live runs and the terms the platform sets.
 */
class NetworkAdminController extends Controller
{
    public function index(): Response
    {
        $settings = NetworkSettings::all();
        $monthStart = CarbonImmutable::now(NetworkSettings::TIMEZONE)->startOfMonth()->utc();
        $delivered = NetworkDelivery::query()->where('status', NetworkDeliveryStatus::Delivered)->where('delivered_at', '>=', $monthStart);

        return Inertia::render('admin/network/Index', [
            'settings' => [
                'enabled' => $settings['enabled'],
                'platformFeePercent' => $settings['platform_fee_bps'] / 100,
                'minFeeCents' => $settings['min_fee_cents'],
                'searchRadiusKm' => $settings['search_radius_km'],
                'billGraceDays' => $settings['bill_grace_days'],
                'pixKey' => $settings['pix_key'],
                'pixHolder' => $settings['pix_holder'],
            ],
            'stats' => [
                'couriersApproved' => NetworkCourier::query()->where('status', CourierStatus::Approved)->count(),
                'couriersPending' => NetworkCourier::query()->where('status', CourierStatus::Pending)->count(),
                'couriersOnline' => NetworkCourier::query()
                    ->where('status', CourierStatus::Approved)
                    ->where('is_online', true)
                    ->where('located_at', '>=', now()->subMinutes(NetworkCourier::LOCATION_FRESH_MINUTES))
                    ->count(),
                'monthRuns' => (clone $delivered)->count(),
                'monthFeesCents' => (int) (clone $delivered)->sum('fee_cents'),
                'monthPlatformCents' => (int) (clone $delivered)->sum('fee_cents') - (int) (clone $delivered)->sum('courier_cents'),
                'billsDueCents' => (int) DeliveryBill::query()->where('status', DeliveryBill::OPEN)->sum('amount_cents'),
                'payoutsPendingCents' => (int) CourierPayout::query()->where('status', CourierPayout::PENDING)->sum('amount_cents'),
            ],
            'live' => NetworkDelivery::query()
                ->whereIn('status', NetworkDeliveryStatus::active())
                ->with(['store:id,name', 'order:id,number', 'courier.user:id,name'])
                ->orderBy('searching_since')
                ->limit(50)
                ->get()
                ->map(fn (NetworkDelivery $delivery) => [
                    'id' => $delivery->id,
                    'storeName' => $delivery->store->name,
                    'orderNumber' => $delivery->order->number,
                    'courierName' => $delivery->courier?->user->name,
                    'status' => $delivery->status->value,
                    'statusLabel' => $delivery->status->label(),
                    'feeCents' => $delivery->fee_cents,
                    'searchingSince' => $delivery->searching_since->toIso8601String(),
                ]),
            'registerUrl' => route('courier.register'),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'platform_fee_percent' => ['required', 'numeric', 'min:0', 'max:90'],
            'min_fee_cents' => ['required', 'integer', 'min:100', 'max:100000'],
            'search_radius_km' => ['required', 'numeric', 'min:1', 'max:50'],
            'bill_grace_days' => ['required', 'integer', 'min:0', 'max:30'],
            'pix_key' => ['nullable', 'string', 'max:140'],
            'pix_holder' => ['nullable', 'string', 'max:120'],
        ]);

        NetworkSettings::save([
            'enabled' => (bool) $data['enabled'],
            'platform_fee_bps' => (int) round((float) $data['platform_fee_percent'] * 100),
            'min_fee_cents' => (int) $data['min_fee_cents'],
            'search_radius_km' => (float) $data['search_radius_km'],
            'bill_grace_days' => (int) $data['bill_grace_days'],
            'pix_key' => $data['pix_key'] ?? null,
            'pix_holder' => $data['pix_holder'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configurações da rede salvas. Valem para as próximas corridas.']);

        return back();
    }
}
