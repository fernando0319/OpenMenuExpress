<?php

namespace App\Models;

use App\Enums\Fulfillment;
use App\Enums\OnlinePaymentMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TabStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $store_id
 * @property int|null $tab_id
 * @property int|null $courier_id
 * @property int $number
 * @property string|null $public_token
 * @property OrderChannel $channel
 * @property Fulfillment $fulfillment
 * @property OrderStatus $status
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $delivery_fee_cents
 * @property int $total_cents
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $scheduled_for
 * @property string $payment_method
 * @property int|null $coupon_id
 * @property string|null $coupon_code
 */
#[Fillable([
    'store_id', 'tab_id', 'number', 'public_token', 'customer_id', 'customer_name', 'customer_phone', 'channel', 'fulfillment', 'status',
    'delivery_zip', 'delivery_street', 'delivery_number', 'delivery_district', 'delivery_city', 'delivery_state',
    'delivery_complement', 'delivery_reference', 'delivery_zone_id', 'courier_id', 'delivery_distance_km', 'coupon_id', 'coupon_code',
    'subtotal_cents', 'discount_cents', 'delivery_fee_cents', 'total_cents',
    'payment_method', 'change_for_cents', 'notes', 'scheduled_for', 'placed_at', 'paid_at',
])]
class Order extends Model
{
    /** payment_method value when the PDV took more than one method. */
    public const PAYMENT_SPLIT = 'split';

    /** payment_method value of a table round: it is paid when the tab closes. */
    public const PAYMENT_TAB = 'tab';

    public function paymentLabel(): string
    {
        if ($this->payment_method === self::PAYMENT_SPLIT) {
            return 'Dividido';
        }

        if ($this->payment_method === self::PAYMENT_TAB) {
            return 'Na conta da mesa';
        }

        $online = OnlinePaymentMethod::tryFrom($this->payment_method);

        if ($online !== null) {
            return $online->orderLabel().($this->paid_at !== null ? ' (pago)' : ' (aguardando)');
        }

        return PaymentMethod::tryFrom($this->payment_method)?->label() ?? $this->payment_method;
    }

    public function isPaidOnline(): bool
    {
        return OnlinePaymentMethod::tryFrom($this->payment_method) !== null;
    }

    /**
     * @return HasMany<OnlinePayment, $this>
     */
    public function onlinePayments(): HasMany
    {
        return $this->hasMany(OnlinePayment::class);
    }

    /**
     * Runs of the courier network called for this order (a cancelled one may be followed by another).
     *
     * @return HasMany<NetworkDelivery, $this>
     */
    public function networkDeliveries(): HasMany
    {
        return $this->hasMany(NetworkDelivery::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'channel' => OrderChannel::class,
            'fulfillment' => Fulfillment::class,
            'status' => OrderStatus::class,
            'delivery_distance_km' => 'decimal:2',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'delivery_fee_cents' => 'integer',
            'total_cents' => 'integer',
            'change_for_cents' => 'integer',
            'scheduled_for' => 'datetime',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Who takes the order to the customer.
     *
     * @return BelongsTo<User, $this>
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    /**
     * @return BelongsTo<Tab, $this>
     */
    public function tab(): BelongsTo
    {
        return $this->belongsTo(Tab::class);
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<DeliveryZone, $this>
     */
    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<OrderChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(OrderChange::class)->orderBy('id');
    }

    /**
     * Still open for edits: not finished, and (for a table round) its tab still open.
     */
    public function isEditable(): bool
    {
        if ($this->status->isFinal()) {
            return false;
        }

        return $this->tab_id === null || Tab::query()->whereKey($this->tab_id)->whereIn('status', [TabStatus::Open, TabStatus::Closing])->exists();
    }
}
