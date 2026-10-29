<?php

namespace App\Models;

use App\Enums\NetworkDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A run a store called the courier network for. The fee and the platform's cut are copied in
 * when it is created, so later changes in the settings never alter it.
 *
 * @property int $id
 * @property int $store_id
 * @property int $order_id
 * @property int|null $network_courier_id
 * @property NetworkDeliveryStatus $status
 * @property int $fee_cents
 * @property int $platform_fee_bps
 * @property int $courier_cents
 * @property float $pickup_latitude
 * @property float $pickup_longitude
 * @property int|null $requested_by
 * @property CarbonImmutable $searching_since
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $picked_up_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $delivery_bill_id
 * @property int|null $courier_payout_id
 * @property CarbonImmutable|null $created_at
 * @property-read Store $store
 * @property-read Order $order
 * @property-read NetworkCourier|null $courier
 */
#[Fillable([
    'store_id', 'order_id', 'network_courier_id', 'status', 'fee_cents', 'platform_fee_bps', 'courier_cents',
    'pickup_latitude', 'pickup_longitude', 'requested_by', 'searching_since', 'accepted_at', 'picked_up_at',
    'delivered_at', 'cancelled_at', 'cancel_reason', 'delivery_bill_id', 'courier_payout_id',
])]
class NetworkDelivery extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NetworkDeliveryStatus::class,
            'fee_cents' => 'integer',
            'platform_fee_bps' => 'integer',
            'courier_cents' => 'integer',
            'pickup_latitude' => 'float',
            'pickup_longitude' => 'float',
            'searching_since' => 'datetime',
            'accepted_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<NetworkCourier, $this>
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(NetworkCourier::class, 'network_courier_id');
    }

    /**
     * @return BelongsTo<DeliveryBill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(DeliveryBill::class, 'delivery_bill_id');
    }

    /**
     * What the platform keeps: the store's fee minus the courier's part.
     */
    public function platformCents(): int
    {
        return $this->fee_cents - $this->courier_cents;
    }
}
