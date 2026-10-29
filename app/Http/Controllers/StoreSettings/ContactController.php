<?php

namespace App\Http\Controllers\StoreSettings;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Courier\CourierProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How customers reach the store and where it is: WhatsApp, e-mail and address, shown on
 * the menu and used by couriers to find the store.
 */
class ContactController extends Controller
{
    use InteractsWithCurrentStore;

    public function edit(): Response
    {
        $store = $this->currentStore();

        return Inertia::render('store-settings/Contact', [
            'store' => [
                'whatsapp' => $store->whatsapp !== null ? preg_replace('/^55/', '', $store->whatsapp) : '',
                'email' => $store->email ?? '',
                'address_zip' => $store->address_zip ?? '',
                'address_street' => $store->address_street ?? '',
                'address_number' => $store->address_number ?? '',
                'address_complement' => $store->address_complement ?? '',
                'address_district' => $store->address_district ?? '',
                'address_city' => $store->address_city ?? '',
                'address_state' => $store->address_state ?? '',
            ],
            'hasLocation' => $store->hasLocation(),
            'states' => CourierProfileRequest::STATES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge([
            'whatsapp' => $request->filled('whatsapp') ? CourierProfileRequest::phone($request->input('whatsapp')) : null,
            'address_zip' => $request->filled('address_zip') ? preg_replace('/\D/', '', (string) $request->input('address_zip')) : null,
            'address_state' => $request->filled('address_state') ? strtoupper((string) $request->input('address_state')) : null,
        ]);

        $data = $request->validate([
            'whatsapp' => ['nullable', 'regex:/^55\d{10,11}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_zip' => ['nullable', 'digits:8'],
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_number' => ['nullable', 'string', 'max:20'],
            'address_complement' => ['nullable', 'string', 'max:255'],
            'address_district' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:255'],
            'address_state' => ['nullable', Rule::in(CourierProfileRequest::STATES)],
        ], [
            'whatsapp.regex' => 'Informe o WhatsApp com DDD.',
            'address_zip.digits' => 'CEP inválido.',
        ]);

        $this->currentStore()->update(array_map(
            fn (mixed $value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value),
            $data,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dados da loja salvos.']);

        return back();
    }
}
