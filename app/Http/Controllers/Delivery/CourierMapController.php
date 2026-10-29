<?php

namespace App\Http\Controllers\Delivery;

use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\DeliveryTracking;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The store's live map of couriers on the way.
 */
class CourierMapController extends Controller
{
    use InteractsWithCurrentStore;

    public function index(DeliveryTracking $tracking): Response
    {
        $store = $this->currentStore();

        $orders = $store->orders()
            ->where('status', OrderStatus::OutForDelivery)
            ->with('courier:id,name')
            ->orderBy('placed_at')
            ->get();

        $courierIds = array_values(array_unique(array_filter($orders->pluck('courier_id')->all(), 'is_int')));
        $positions = $tracking->latestPositions($store, $courierIds);

        return Inertia::render('delivery/Map', [
            'couriers' => array_map(function (int $courierId) use ($orders, $positions): array {
                $courierOrders = $orders->where('courier_id', $courierId);
                $courier = $courierOrders->first()?->courier;

                return [
                    'id' => $courierId,
                    'name' => $courier instanceof User ? $courier->name : '',
                    'position' => $positions->get($courierId)?->toPoint(),
                    'orders' => $courierOrders->map(fn (Order $order) => ['id' => $order->id, 'number' => $order->number, 'district' => $order->delivery_district])->values(),
                ];
            }, $courierIds),
            'unassigned' => $orders->whereNull('courier_id')->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'district' => $order->delivery_district,
            ])->values(),
            'mapTiles' => ['url' => config('maps.tile_url'), 'attribution' => config('maps.attribution')],
        ]);
    }
}
