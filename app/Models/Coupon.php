<?php

namespace App\Models;

use App\Enums\CouponType;
use App\Enums\Fulfillment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $store_id
 * @property string $code
 * @property string|null $description
 * @property CouponType $type
 * @property int|null $percent
 * @property int|null $amount_cents
 * @property int|null $max_discount_cents
 * @property int $min_subtotal_cents
 * @property int|null $usage_limit
 * @property int|null $per_customer_limit
 * @property bool $first_order_only
 * @property Fulfillment|null $fulfillment
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $is_active
 */
#[Fillable([
    'store_id', 'code', 'description', 'type', 'percent', 'amount_cents', 'max_discount_cents', 'min_subtotal_cents',
    'usage_limit', 'per_customer_limit', 'first_order_only', 'fulfillment', 'starts_at', 'ends_at', 'is_active',
])]
class Coupon extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'fulfillment' => Fulfillment::class,
            'percent' => 'integer',
            'amount_cents' => 'integer',
            'max_discount_cents' => 'integer',
            'min_subtotal_cents' => 'integer',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'first_order_only' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Codes are compared ignoring case and spaces: "promo10" = "PROMO10".
     */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', $code));
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
