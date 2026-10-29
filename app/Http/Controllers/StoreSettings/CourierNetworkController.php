<?php

namespace App\Http\Controllers\StoreSettings;

use App\Enums\BillingCycle;
use App\Enums\NetworkDeliveryStatus;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\DeliveryBill;
use App\Models\NetworkDelivery;
use App\Services\CourierNetwork\NetworkBilling;
use App\Support\NetworkSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The store's side of the courier network: joining it, the usual fee per run, where the
 * store is on the map, and the bills of the runs.
 */
class CourierNetworkController extends Controller
{
    use InteractsWithCurrentStore;

    public function edit(NetworkBilling $billing): Response
    {
        $store = $this->currentStore();
        $settings = NetworkSettings::all();

        return Inertia::render('store-settings/CourierNetwork', [
            'available' => $settings['enabled'],
            'settings' => [
                'enabled' => $store->network_enabled,
                'feeCents' => $store->network_fee_cents,
                'cycle' => $store->network_billing_cycle->value,
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
            ],
            'address' => implode(', ', array_filter([
                trim("{$store->address_street}, {$store->address_number}", ', '),
                $store->address_district,
                $store->address_city,
                $store->address_state,
            ])),
            'cycles' => array_map(fn (BillingCycle $cycle) => [
                'value' => $cycle->value,
                'label' => $cycle->label(),
                'description' => $cycle->description(),
            ], BillingCycle::cases()),
            'minFeeCents' => $settings['min_fee_cents'],
            'pix' => ['key' => $settings['pix_key'], 'holder' => $settings['pix_holder']],
            'contactWhatsapp' => config('billing.contact_whatsapp'),
            'unbilled' => $billing->unbilled($store),
            'overdue' => $billing->overdueBill($store) !== null,
            'bills' => $store->deliveryBills()->orderByDesc('period_start')->limit(20)->get()->map(fn (DeliveryBill $bill) => [
                'id' => $bill->id,
                'period' => $bill->periodLabel(),
                'cycle' => $bill->cycle->label(),
                'count' => $bill->deliveries_count,
                'amountCents' => $bill->amount_cents,
                'dueAt' => $bill->due_at->toIso8601String(),
                'status' => $bill->status,
                'statusLabel' => $bill->statusLabel(),
                'overdue' => $bill->isOverdue(),
            ]),
            'recent' => $store->networkDeliveries()
                ->with(['order:id,number,customer_name', 'courier.user:id,name'])
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (NetworkDelivery $delivery) => [
                    'id' => $delivery->id,
                    'orderId' => $delivery->order_id,
                    'orderNumber' => $delivery->order->number,
                    'courierName' => $delivery->courier?->user->name,
                    'status' => $delivery->status->value,
                    'statusLabel' => $delivery->status->label(),
                    'feeCents' => $delivery->fee_cents,
                    'createdAt' => $delivery->created_at?->toIso8601String(),
                    'charged' => $delivery->status === NetworkDeliveryStatus::Delivered,
                ]),
            'mapTiles' => ['url' => config('maps.tile_url'), 'attribution' => config('maps.attribution')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $minFee = NetworkSettings::all()['min_fee_cents'];

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'fee_cents' => ['nullable', 'integer', "min:{$minFee}", 'max:100000'],
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ], [
            'fee_cents.min' => 'O valor mínimo da corrida é R$ '.number_format($minFee / 100, 2, ',', '.').'.',
        ]);

        if ($data['enabled'] && ($data['latitude'] ?? null) === null) {
            return back()->withErrors(['latitude' => 'Marque no mapa onde fica a loja: é lá que o entregador vai buscar o pedido.']);
        }

        $this->currentStore()->update([
            'network_enabled' => $data['enabled'],
            'network_fee_cents' => $data['fee_cents'] ?? null,
            'network_billing_cycle' => $data['cycle'],
            'latitude' => isset($data['latitude']) ? round((float) $data['latitude'], 7) : null,
            'longitude' => isset($data['longitude']) ? round((float) $data['longitude'], 7) : null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rede de entregadores atualizada.']);

        return back();
    }
}
