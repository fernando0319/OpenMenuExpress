<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $voucher_id
 * @property int $store_id
 * @property int|null $user_id
 * @property int $days
 * @property CarbonImmutable|null $paid_until_before
 * @property CarbonImmutable $paid_until_after
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['voucher_id', 'store_id', 'user_id', 'days', 'paid_until_before', 'paid_until_after'])]
class VoucherRedemption extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'paid_until_before' => 'datetime',
            'paid_until_after' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
