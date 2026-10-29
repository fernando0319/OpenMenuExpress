<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shift of a weekday, in the store's local time. A shift whose closing time is not
 * after its opening time runs past midnight (18:00–02:00); equal times mean 24 hours.
 *
 * @property int $id
 * @property int $store_id
 * @property int $weekday 0 = domingo … 6 = sábado
 * @property string $opens_at HH:MM[:SS]
 * @property string $closes_at HH:MM[:SS]
 */
#[Fillable(['store_id', 'weekday', 'opens_at', 'closes_at'])]
class OpeningHour extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function opensAtMinutes(): int
    {
        return self::toMinutes($this->opens_at);
    }

    public function closesAtMinutes(): int
    {
        return self::toMinutes($this->closes_at);
    }

    public function crossesMidnight(): bool
    {
        return $this->closesAtMinutes() <= $this->opensAtMinutes();
    }

    public static function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [0, 0]);

        return $hours * 60 + $minutes;
    }
}
