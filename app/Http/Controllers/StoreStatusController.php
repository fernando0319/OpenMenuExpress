<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The "open / close the store" switch on the home screen. Closing pauses the menu for new
 * orders right away; opening lifts the pause (the opening hours, when set, still apply).
 */
class StoreStatusController extends Controller
{
    use InteractsWithCurrentStore;

    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate(['open' => ['required', 'boolean']]);
        $open = (bool) $validated['open'];

        $this->currentStore()->update(['is_paused' => ! $open]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $open ? 'Loja aberta para pedidos.' : 'Loja fechada. O cardápio não aceita pedidos até você abrir de novo.',
        ]);

        return back();
    }
}
