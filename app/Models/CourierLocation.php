<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a courier was at a moment of a delivery.
 *
 * @property int $id
 * @property int $store_id
 * @property int $user_id
 * @property float $latitude
 * @property float $longitude
 * @property int|null $accuracy_m
 * @property CarbonImmutable $recorded_at
 */
#[Fillable(['store_id', 'user_id', 'latitude', 'longitude', 'accuracy_m', 'recorded_at'])]
class CourierLocation extends Model
{
    public $timestamps = false;

    /** Days a position is kept before being deleted. */
    public const RETENTION_DAYS = 7;

    /** A position older than this is not shown as "live". */
    public const LIVE_MINUTES = 5;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return array{lat: float, lng: float, accuracy: int|null, at: string}
     */
    public function toPoint(): array
    {
        return [
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'accuracy' => $this->accuracy_m,
            'at' => $this->recorded_at->toIso8601String(),
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
