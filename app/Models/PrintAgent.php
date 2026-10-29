<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string $token_hash
 * @property string|null $platform
 * @property string|null $version
 * @property list<string>|null $available_printers
 * @property CarbonImmutable|null $last_seen_at
 */
#[Fillable(['store_id', 'name', 'token_hash', 'platform', 'version', 'available_printers', 'last_seen_at'])]
#[Hidden(['token_hash'])]
class PrintAgent extends Model
{
    /** Without a heartbeat for this long, the agent is shown as offline. */
    public const ONLINE_WINDOW_SECONDS = 45;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available_printers' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan(now()->subSeconds(self::ONLINE_WINDOW_SECONDS));
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<Printer, $this>
     */
    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }
}
