<?php

namespace App\Support;

use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The platform admin looking at a store's panel to help (support mode).
 *
 * The admin stays signed in as themselves: the panel shows the chosen store as its owner
 * would see it, and everything done is recorded under the admin's name.
 *
 * @phpstan-type SupportData array{store_id: int, store_name: string, mode: string, reason: string, started_at: string, expires_at: string}
 */
final class SupportSession
{
    public const MODE_READ = 'read';

    public const MODE_FULL = 'full';

    public const MINUTES = 60;

    private const KEY = 'support_session';

    public static function start(Request $request, Store $store, string $mode, string $reason): void
    {
        $now = CarbonImmutable::now();

        $request->session()->put(self::KEY, [
            'store_id' => $store->id,
            'store_name' => $store->name,
            'mode' => $mode,
            'reason' => $reason,
            'started_at' => $now->toIso8601String(),
            'expires_at' => $now->addMinutes(self::MINUTES)->toIso8601String(),
        ]);
    }

    /**
     * The support session in this request, expired or not.
     *
     * @return SupportData|null
     */
    public static function raw(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $data = $request->session()->get(self::KEY);

        if (! is_array($data) || ! is_int($data['store_id'] ?? null) || ! is_string($data['expires_at'] ?? null)) {
            return null;
        }

        return [
            'store_id' => $data['store_id'],
            'store_name' => (string) ($data['store_name'] ?? ''),
            'mode' => ($data['mode'] ?? null) === self::MODE_FULL ? self::MODE_FULL : self::MODE_READ,
            'reason' => (string) ($data['reason'] ?? ''),
            'started_at' => (string) ($data['started_at'] ?? ''),
            'expires_at' => $data['expires_at'],
        ];
    }

    /**
     * The active (not expired) support session.
     *
     * @return SupportData|null
     */
    public static function current(Request $request): ?array
    {
        $data = self::raw($request);

        return $data !== null && ! self::isExpired($data) ? $data : null;
    }

    /**
     * @param  SupportData  $data
     */
    public static function isExpired(array $data): bool
    {
        return CarbonImmutable::parse($data['expires_at'])->isPast();
    }

    public static function stop(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::KEY);
        }
    }
}
