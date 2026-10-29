<?php

namespace App\Models;

use App\Enums\CourierStatus;
use App\Enums\NetworkDeliveryStatus;
use App\Enums\PixKeyType;
use App\Enums\Vehicle;
use Carbon\CarbonImmutable;
use Database\Factories\NetworkCourierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A courier registered with the platform's network, available to every store nearby.
 *
 * @property int $id
 * @property int $user_id
 * @property string $cpf
 * @property string $phone
 * @property string $city
 * @property string $state
 * @property Vehicle $vehicle
 * @property string|null $vehicle_plate
 * @property PixKeyType $pix_key_type
 * @property string $pix_key
 * @property string|null $document_path
 * @property CourierStatus $status
 * @property string|null $status_reason
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by
 * @property bool $is_online
 * @property float|null $latitude
 * @property float|null $longitude
 * @property CarbonImmutable|null $located_at
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'cpf', 'phone', 'city', 'state', 'vehicle', 'vehicle_plate', 'pix_key_type', 'pix_key', 'document_path',
    'status', 'status_reason', 'reviewed_at', 'reviewed_by', 'is_online', 'latitude', 'longitude', 'located_at',
])]
class NetworkCourier extends Model
{
    /** @use HasFactory<NetworkCourierFactory> */
    use HasFactory;

    /**
     * Without a position this recent, an online courier gets no offers. Phones stop sending
     * the position while the screen is off, so the last one counts for a while; past it the
     * courier is taken offline (app:expire-courier-presence).
     */
    public const LOCATION_FRESH_MINUTES = 120;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle' => Vehicle::class,
            'pix_key_type' => PixKeyType::class,
            'status' => CourierStatus::class,
            'reviewed_at' => 'datetime',
            'is_online' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'located_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<NetworkDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NetworkDelivery::class);
    }

    /**
     * @return HasMany<CourierPayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(CourierPayout::class);
    }

    public function isApproved(): bool
    {
        return $this->status === CourierStatus::Approved;
    }

    /**
     * The run in progress (accepted or picked up). A courier takes one at a time.
     */
    public function activeDelivery(): ?NetworkDelivery
    {
        return $this->deliveries()
            ->whereIn('status', [NetworkDeliveryStatus::Accepted, NetworkDeliveryStatus::PickedUp])
            ->latest('id')
            ->first();
    }

    /**
     * Online, with a recent position: can be offered runs.
     */
    public function isAvailable(): bool
    {
        return $this->isApproved()
            && $this->is_online
            && $this->latitude !== null
            && $this->longitude !== null
            && $this->located_at !== null
            && $this->located_at->greaterThan(now()->subMinutes(self::LOCATION_FRESH_MINUTES));
    }

    /**
     * "Moto · ABC1D23".
     */
    public function vehicleLabel(): string
    {
        return $this->vehicle->label().($this->vehicle_plate !== null ? ' · '.$this->vehicle_plate : '');
    }
}
