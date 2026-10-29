<?php

namespace App\Http\Controllers\StoreSettings;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the store hands orders over: pickup, delivery by neighborhood and the minimum order.
 */
class DeliveryController extends Controller
{
    use InteractsWithCurrentStore;

    public function edit(): Response
    {
        $store = $this->currentStore();

        return Inertia::render('store-settings/Delivery', [
            'settings' => [
                'accepts_delivery' => $store->accepts_delivery,
                'accepts_pickup' => $store->accepts_pickup,
                'minimum_order' => Money::toInput($store->minimum_order_cents),
                'is_paused' => $store->is_paused,
            ],
            'zones' => $store->deliveryZones()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (DeliveryZone $zone) => [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'fee' => Money::toInput($zone->fee_cents),
                    'fee_cents' => $zone->fee_cents,
                    'is_active' => $zone->is_active,
                ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['minimum_order_cents' => Money::parseToCents($request->input('minimum_order') ?: '0')]);

        $data = $request->validate([
            'accepts_delivery' => ['boolean'],
            'accepts_pickup' => ['boolean'],
            'is_paused' => ['boolean'],
            'minimum_order_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
        ], [
            'minimum_order_cents.required' => 'Valor inválido. Use o formato 30,00.',
            'minimum_order_cents.integer' => 'Valor inválido. Use o formato 30,00.',
        ]);

        $this->currentStore()->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configurações de entrega salvas.']);

        return to_route('store-settings.delivery.edit');
    }

    public function storeZone(Request $request): RedirectResponse
    {
        $this->currentStore()->deliveryZones()->create($this->validatedZone($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bairro adicionado.']);

        return to_route('store-settings.delivery.edit');
    }

    public function updateZone(Request $request, DeliveryZone $zone): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($zone);

        $zone->update($this->validatedZone($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bairro atualizado.']);

        return to_route('store-settings.delivery.edit');
    }

    public function destroyZone(DeliveryZone $zone): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($zone);

        $zone->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bairro removido.']);

        return to_route('store-settings.delivery.edit');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedZone(Request $request): array
    {
        $fee = $request->input('fee');
        $request->merge(['fee_cents' => $fee === null || $fee === '' ? 0 : Money::parseToCents($fee)]);

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'fee_cents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['boolean'],
        ], [
            'fee_cents.required' => 'Taxa inválida. Use o formato 5,00.',
            'fee_cents.integer' => 'Taxa inválida. Use o formato 5,00.',
        ]);
    }
}
