<?php

namespace App\Http\Controllers\Courier\Concerns;

use App\Enums\NetworkDeliveryStatus;
use App\Models\NetworkCourier;
use App\Models\NetworkDelivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait InteractsWithCourier
{
    protected function courier(Request $request): NetworkCourier
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $courier = $user->networkCourier;
        abort_unless($courier instanceof NetworkCourier, 403);

        return $courier;
    }

    protected function approvedCourier(Request $request): NetworkCourier
    {
        $courier = $this->courier($request);

        if (! $courier->isApproved()) {
            throw ValidationException::withMessages(['courier' => 'Seu cadastro ainda não foi aprovado.']);
        }

        return $courier;
    }

    /**
     * What the courier must charge at the door, or null when the order is already paid.
     *
     * @return array{amountCents: int, method: string, changeForCents: int|null}|null
     */
    protected function collect(Order $order): ?array
    {
        if ($order->paid_at !== null) {
            return null;
        }

        return [
            'amountCents' => $order->total_cents,
            'method' => $order->paymentLabel(),
            'changeForCents' => $order->change_for_cents,
        ];
    }

    /**
     * The run in progress, with everything needed to pick it up and deliver it.
     *
     * @return array<string, mixed>
     */
    protected function activePayload(NetworkDelivery $delivery): array
    {
        $store = $delivery->store;
        $order = $delivery->order;
        $storeAddress = trim("{$store->address_street}, {$store->address_number}", ', ');
        $customerAddress = trim("{$order->delivery_street}, {$order->delivery_number}", ', ');

        return [
            'id' => $delivery->id,
            'status' => $delivery->status->value,
            // Told from the courier's side: the store-facing label says "courier on the way".
            'statusLabel' => $delivery->status === NetworkDeliveryStatus::Accepted ? 'Indo até a loja' : 'Levando ao cliente',
            'courierCents' => $delivery->courier_cents,
            'acceptedAt' => $delivery->accepted_at?->toIso8601String(),
            'store' => [
                'name' => $store->name,
                'phone' => $store->whatsapp,
                'address' => $storeAddress,
                'district' => $store->address_district,
                'complement' => $store->address_complement,
                'lat' => $delivery->pickup_latitude,
                'lng' => $delivery->pickup_longitude,
            ],
            'order' => [
                'number' => $order->number,
                'customerName' => $order->customer_name,
                'customerPhone' => $order->customer_phone,
                'address' => $customerAddress,
                'complement' => $order->delivery_complement,
                'district' => $order->delivery_district,
                'city' => $order->delivery_city,
                'reference' => $order->delivery_reference,
                'mapQuery' => implode(', ', array_filter([$customerAddress, $order->delivery_district, $order->delivery_city])),
                'notes' => $order->notes,
                'collect' => $this->collect($order),
            ],
        ];
    }
}
