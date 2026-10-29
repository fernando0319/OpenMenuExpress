<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A code the admin hands to a merchant after payment: adds days of a plan to the store.
 *
 * @property int $id
 * @property string $code
 * @property int $plan_id
 * @property int $days
 * @property int $max_redemptions
 * @property int $redemptions_count
 * @property CarbonImmutable|null $expires_at
 * @property bool $is_active
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['code', 'plan_id', 'days', 'max_redemptions', 'redemptions_count', 'expires_at', 'is_active', 'note', 'created_by'])]
class Voucher extends Model
{
    /** No 0/O, 1/I/L: codes are read and typed by people. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Upper case, without spaces; accepts the code typed with or without dashes.
     */
    public static function normalize(string $code): string
    {
        $clean = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));

        if (strlen($clean) === 10 && str_starts_with($clean, 'MX')) {
            return 'MX-'.substr($clean, 2, 4).'-'.substr($clean, 6, 4);
        }

        return $clean;
    }

    public static function generateCode(): string
    {
        $part = fn (): string => implode('', array_map(
            fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)],
            range(1, 4),
        ));

        return "MX-{$part()}-{$part()}";
    }

    public function statusLabel(): string
    {
        return match (true) {
            ! $this->is_active => 'Desativado',
            $this->redemptions_count >= $this->max_redemptions => 'Usado',
            $this->expires_at !== null && $this->expires_at->isPast() => 'Expirado',
            default => 'Disponível',
        };
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<VoucherRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }
}
