<?php

namespace App\Http\Controllers\StoreSettings;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Services\Payments\StorePaymentMethods;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The payment methods the store accepts on the menu, the PDV and the tables.
 */
class PaymentsController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly StorePaymentMethods $paymentMethods) {}

    public function edit(): Response
    {
        return Inertia::render('store-settings/Payments', [
            'methods' => array_map(
                fn (array $row) => ['value' => $row['method']->value, 'label' => $row['method']->label(), 'active' => $row['active']],
                $this->paymentMethods->settings($this->currentStore()),
            ),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'methods' => ['required', 'array', 'max:50'],
            'methods.*.value' => ['required', 'string', 'max:40'],
            'methods.*.active' => ['boolean'],
        ]);

        $ordered = [];
        $active = [];

        foreach ($data['methods'] as $row) {
            $ordered[] = (string) $row['value'];

            if ((bool) ($row['active'] ?? false)) {
                $active[] = (string) $row['value'];
            }
        }

        $this->paymentMethods->save($this->currentStore(), $ordered, $active);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Formas de pagamento salvas.']);

        return back();
    }
}
