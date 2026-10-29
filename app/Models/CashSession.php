<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $store_id
 * @property int|null $opened_by
 * @property int|null $closed_by
 * @property int $opening_cents
 * @property int|null $expected_cash_cents
 * @property int|null $counted_cash_cents
 * @property string|null $notes
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 */
#[Fillable(['store_id', 'opened_by', 'closed_by', 'opening_cents', 'expected_cash_cents', 'counted_cash_cents', 'notes', 'opened_at', 'closed_at'])]
class CashSession extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_cents' => 'integer',
            'expected_cash_cents' => 'integer',
            'counted_cash_cents' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Counted minus expected: positive is extra cash, negative is missing cash.
     */
    public function differenceCents(): ?int
    {
        if ($this->counted_cash_cents === null || $this->expected_cash_cents === null) {
            return null;
        }

        return $this->counted_cash_cents - $this->expected_cash_cents;
    }

    /**
     * @param  Builder<CashSession>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
