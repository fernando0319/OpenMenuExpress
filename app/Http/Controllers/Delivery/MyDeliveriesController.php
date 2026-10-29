<?php

namespace App\Http\Controllers\Delivery;

use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\DeliveryTracking;
use App\Services\Orders\ChangeOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The courier's screen on the phone: their deliveries, and the location sharing.
 */
class MyDeliveriesController extends Controller
{
    use InteractsWithCurrentStore;

    private const ACTIVE = [OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery];

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        $orders = $this->currentStore()->orders()
            ->where('courier_id', $user->id)
            ->whereIn('status', self::ACTIVE)
            ->orderByRaw('case when status = ? then 0 else 1 end', [OrderStatus::OutForDelivery->value])
            ->orderBy('placed_at')
            ->get();

        return Inertia::render('delivery/Index', [
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'customerName' => $order->customer_name,
                'customerPhone' => $order->customer_phone,
                'address' => trim("{$order->delivery_street}, {$order->delivery_number}"),
                'complement' => $order->delivery_complement,
                'district' => $order->delivery_district,
                'reference' => $order->delivery_reference,
                'mapQuery' => trim("{$order->delivery_street}, {$order->delivery_number}, {$order->delivery_district}, {$order->delivery_city}"),
                'totalCents' => $order->total_cents,
                'paymentMethod' => $order->paymentLabel(),
                'paid' => $order->paid_at !== null,
                'changeForCents' => $order->change_for_cents,
                'notes' => $order->notes,
            ]),
        ]);
    }

    /**
     * Receives the phone's position. Ignored when the courier has nothing on the way.
     */
    public function location(Request $request, DeliveryTracking $tracking): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $stored = $tracking->record(
            $this->currentStore(),
            $this->user($request),
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null,
        );

        return response()->json(['tracking' => $stored]);
    }

    public function depart(Request $request, Order $order, ChangeOrderStatus $changeStatus): RedirectResponse
    {
        $this->ensureMine($request, $order);

        $changeStatus->handle($order, OrderStatus::OutForDelivery, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pedido nº {$order->number} saiu para entrega."]);

        return back();
    }

    public function complete(Request $request, Order $order, ChangeOrderStatus $changeStatus): RedirectResponse
    {
        $this->ensureMine($request, $order);

        if ($order->status !== OrderStatus::OutForDelivery) {
            throw ValidationException::withMessages(['status' => 'Marque a saída para entrega antes.']);
        }

        $changeStatus->handle($order, OrderStatus::Completed, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pedido nº {$order->number} entregue."]);

        return back();
    }

    private function ensureMine(Request $request, Order $order): void
    {
        $this->ensureBelongsToCurrentStore($order);

        abort_unless($order->courier_id === $this->user($request)->id, 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
