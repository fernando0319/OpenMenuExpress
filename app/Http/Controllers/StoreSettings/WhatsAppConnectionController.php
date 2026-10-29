<?php

namespace App\Http\Controllers\StoreSettings;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Messaging\Evolution\StoreWhatsAppConnection;
use App\Messaging\MessagingException;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WhatsAppConnectionController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly StoreWhatsAppConnection $connection) {}

    public function connect(Request $request): RedirectResponse
    {
        $request->validate(['phone' => ['nullable', 'string', 'max:25']]);

        $phone = $request->filled('phone') ? Phone::normalize($request->input('phone')) : null;

        if ($request->filled('phone') && $phone === null) {
            return back()->withErrors(['phone' => 'Informe o número com DDD, por exemplo (11) 99999-0000.']);
        }

        try {
            $this->connection->connect($this->currentStore(), $phone, $request->session());
        } catch (MessagingException $exception) {
            return back()->withErrors(['connection' => $exception->getMessage()]);
        }

        return to_route('store-settings.messages.edit');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        try {
            $this->connection->disconnect($this->currentStore(), $request->session());
        } catch (MessagingException $exception) {
            return back()->withErrors(['connection' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'WhatsApp desconectado.']);

        return to_route('store-settings.messages.edit');
    }
}
