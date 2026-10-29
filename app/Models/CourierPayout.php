<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PixKeyType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the platform pays a courier for the runs delivered in a week (Monday to Sunday).
 * The PIX key is copied in, so the payment goes where it was at closing time.
 *
 * @property int $id
 * @property int $network_courier_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int $deliveries_count
 * @property int $amount_cents
 * @property PixKeyType $pix_key_type
 * @property string $pix_key
 * @property string $status pending | paid
 * @property CarbonImmutable|null $paid_at
 * @property int|null $paid_by
 * @property string|null $note
 * @property-read NetworkCourier $courier
 */
#[Fillable([
    'network_courier_id', 'period_start', 'period_end', 'deliveries_count', 'amount_cents', 'pix_key_type', 'pix_key',
    'status', 'paid_at', 'paid_by', 'note',
])]
class CourierPayout extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => DateOnly::class,
            'period_end' => DateOnly::class,
            'deliveries_count' => 'integer',
            'amount_cents' => 'integer',
            'pix_key_type' => PixKeyType::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NetworkCourier, $this>
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(NetworkCourier::class, 'network_courier_id');
    }

    /**
     * @return HasMany<NetworkDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NetworkDelivery::class);
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('d/m').' a '.$this->period_end->format('d/m/Y');
    }
}
