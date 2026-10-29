<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a store owes the platform for the network runs of a day or a week.
 *
 * @property int $id
 * @property int $store_id
 * @property BillingCycle $cycle
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int $deliveries_count
 * @property int $amount_cents
 * @property CarbonImmutable $due_at
 * @property string $status open | paid | waived
 * @property CarbonImmutable|null $settled_at
 * @property int|null $settled_by
 * @property string|null $note
 * @property-read Store $store
 */
#[Fillable([
    'store_id', 'cycle', 'period_start', 'period_end', 'deliveries_count', 'amount_cents', 'due_at',
    'status', 'settled_at', 'settled_by', 'note',
])]
class DeliveryBill extends Model
{
    public const OPEN = 'open';

    public const PAID = 'paid';

    public const WAIVED = 'waived';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle' => BillingCycle::class,
            'period_start' => DateOnly::class,
            'period_end' => DateOnly::class,
            'deliveries_count' => 'integer',
            'amount_cents' => 'integer',
            'due_at' => 'datetime',
            'settled_at' => 'datetime',
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
     * @return HasMany<NetworkDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NetworkDelivery::class);
    }

    public function isOverdue(?CarbonImmutable $now = null): bool
    {
        return $this->status === self::OPEN && $this->due_at->lessThan($now ?? CarbonImmutable::now());
    }

    /**
     * "22/10/2026" for a day, "19/10 a 25/10/2026" for a week.
     */
    public function periodLabel(): string
    {
        return $this->period_start->equalTo($this->period_end)
            ? $this->period_start->format('d/m/Y')
            : $this->period_start->format('d/m').' a '.$this->period_end->format('d/m/Y');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PAID => 'Paga',
            self::WAIVED => 'Dispensada',
            default => $this->isOverdue() ? 'Vencida' : 'Em aberto',
        };
    }
}
