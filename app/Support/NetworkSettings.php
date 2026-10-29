<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * The courier network terms the platform owner sets in /admin.
 */
final class NetworkSettings
{
    private const CACHE_KEY = 'platform-settings:network';

    /** @var array{enabled: bool, platform_fee_bps: int, min_fee_cents: int, search_radius_km: float, bill_grace_days: int, pix_key: string|null, pix_holder: string|null} */
    public const DEFAULTS = [
        'enabled' => false,
        'platform_fee_bps' => 1500,
        'min_fee_cents' => 500,
        'search_radius_km' => 8.0,
        'bill_grace_days' => 1,
        'pix_key' => null,
        'pix_holder' => null,
    ];

    /** Timezone of the weekly courier payouts. */
    public const TIMEZONE = 'America/Sao_Paulo';

    /**
     * @return array{enabled: bool, platform_fee_bps: int, min_fee_cents: int, search_radius_km: float, bill_grace_days: int, pix_key: string|null, pix_holder: string|null}
     */
    public static function all(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, fn (): array => PlatformSetting::query()
            ->where('key', 'like', 'network.%')
            ->pluck('value', 'key')
            ->mapWithKeys(fn (mixed $value, string $key) => [substr($key, strlen('network.')) => $value])
            ->all());

        $defaults = self::DEFAULTS;

        return [
            'enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']),
            'platform_fee_bps' => (int) ($stored['platform_fee_bps'] ?? $defaults['platform_fee_bps']),
            'min_fee_cents' => (int) ($stored['min_fee_cents'] ?? $defaults['min_fee_cents']),
            'search_radius_km' => (float) ($stored['search_radius_km'] ?? $defaults['search_radius_km']),
            'bill_grace_days' => (int) ($stored['bill_grace_days'] ?? $defaults['bill_grace_days']),
            'pix_key' => isset($stored['pix_key']) ? (string) $stored['pix_key'] : null,
            'pix_holder' => isset($stored['pix_holder']) ? (string) $stored['pix_holder'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            PlatformSetting::query()->updateOrCreate(['key' => "network.{$key}"], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    public static function enabled(): bool
    {
        return self::all()['enabled'];
    }

    /**
     * The courier's part of a fee, after the platform's cut (rounded in the courier's favor).
     */
    public static function courierCents(int $feeCents, int $platformFeeBps): int
    {
        return $feeCents - intdiv($feeCents * $platformFeeBps, 10_000);
    }
}
