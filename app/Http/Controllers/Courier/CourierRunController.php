<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Courier\Concerns\InteractsWithCourier;
use App\Models\NetworkDelivery;
use App\Services\CourierNetwork\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * What the courier does with a run: take it or pass, give it back, pick it up, deliver it.
 */
class CourierRunController extends Controller
{
    use InteractsWithCourier;

    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function accept(Request $request, NetworkDelivery $delivery): RedirectResponse
    {
        $this->dispatcher->accept($delivery, $this->approvedCourier($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Corrida aceita. Vá até a {$delivery->store->name}."]);

        return back();
    }

    public function decline(Request $request, NetworkDelivery $delivery): RedirectResponse
    {
        $this->dispatcher->decline($delivery, $this->approvedCourier($request));

        return back();
    }

    public function giveUp(Request $request, NetworkDelivery $delivery): RedirectResponse
    {
        $this->dispatcher->giveUp($delivery, $this->approvedCourier($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Corrida devolvida. Outro entregador vai buscá-la.']);

        return back();
    }

    public function pickUp(Request $request, NetworkDelivery $delivery): RedirectResponse
    {
        $this->dispatcher->pickUp($delivery, $this->approvedCourier($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pedido retirado. Boa entrega!']);

        return back();
    }

    public function complete(Request $request, NetworkDelivery $delivery): RedirectResponse
    {
        $this->dispatcher->complete($delivery, $this->approvedCourier($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entrega concluída. O valor entra no seu repasse da semana.']);

        return back();
    }
}
